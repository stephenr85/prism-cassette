<?php

use Illuminate\Support\Collection;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Providers\Provider;
use Prism\Prism\Text\Request;
use Prism\Prism\Text\Response;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;
use Rushing\PrismCassette\Tests\TestCase;

// Feature/Passthrough and Feature/Disarmed bind their scenario TestCase per-file.
uses(TestCase::class)->in('Feature/*.php');

// ── Shared fakes bound at the Prism seam (never live providers) ────────────

function footgunTempDir(): string
{
    return sys_get_temp_dir().'/cassette-footgun-'.uniqid();
}

function footgunTextRequest(string $prompt): Request
{
    return new Request(
        model: 'gpt-4o',
        providerKey: 'openai',
        systemPrompts: [],
        prompt: null,
        messages: [new UserMessage($prompt)],
        maxSteps: 1,
        maxTokens: null,
        temperature: null,
        topP: null,
        tools: [],
        clientOptions: [],
        clientRetry: [0],
        toolChoice: null,
    );
}

function footgunCountingDelegate(int &$count, string $text): Provider
{
    return new class($count, $text) extends Provider
    {
        public function __construct(public int &$calls, private string $text) {}

        #[Override]
        public function text(Request $request): Response
        {
            $this->calls++;

            return new Response(
                steps: new Collection,
                text: $this->text,
                finishReason: FinishReason::Stop,
                toolCalls: [],
                toolResults: [],
                usage: new Usage(1, 1),
                meta: new Meta('id', 'gpt-4o'),
                messages: new Collection,
            );
        }
    };
}

/** @return string[] */
function footgunJsonFiles(string $dir): array
{
    if (! is_dir($dir)) {
        return [];
    }

    $result = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );

    foreach ($it as $file) {
        if ($file->isFile() && $file->getExtension() === 'json') {
            $result[] = $file->getPathname();
        }
    }

    return $result;
}
