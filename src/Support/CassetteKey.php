<?php

namespace Rushing\PrismCassette\Support;

use Prism\Prism\Contracts\Message;
use Prism\Prism\Embeddings\Request as EmbeddingsRequest;
use Prism\Prism\Structured\Request as StructuredRequest;
use Prism\Prism\Text\Request as TextRequest;
use Prism\Prism\Tool;
use Rushing\PrismCassette\Contracts\CassetteKeyResolver;

class CassetteKey implements CassetteKeyResolver
{
    public function forEmbeddings(EmbeddingsRequest $request): string
    {
        $payload = [
            'type' => 'embeddings',
            'provider' => $request->provider(),
            'model' => $request->model(),
            'inputs' => array_map(
                fn (string $s) => $this->normalizeWhitespace(mb_scrub($s)),
                $request->inputs()
            ),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function forText(TextRequest $request): string
    {
        $payload = [
            'type' => 'text',
            'provider' => $request->provider(),
            'model' => $request->model(),
            'system_prompts' => array_map(
                fn ($sp) => $this->normalizeWhitespace($sp->content),
                $request->systemPrompts()
            ),
            'messages' => $this->normalizePayload($this->serializeMessages($request->messages())),
            'max_tokens' => $request->maxTokens(),
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'tools' => $this->serializeTools($request->tools()),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function forStructured(StructuredRequest $request): string
    {
        $payload = [
            'type' => 'structured',
            'provider' => $request->provider(),
            'model' => $request->model(),
            'system_prompts' => array_map(
                fn ($sp) => $this->normalizeWhitespace($sp->content),
                $request->systemPrompts()
            ),
            'messages' => $this->normalizePayload($this->serializeMessages($request->messages())),
            'max_tokens' => $request->maxTokens(),
            'temperature' => $request->temperature(),
            'top_p' => $request->topP(),
            'tools' => $this->serializeTools($request->tools()),
            'schema' => $request->schema()->toArray(),
            'mode' => $request->mode()->name,
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function normalizeWhitespace(string $text): string
    {
        return (string) preg_replace('/\s+/', ' ', trim($text));
    }

    private function normalizePayload(mixed $value): mixed
    {
        if (is_string($value)) {
            return $this->normalizeWhitespace($value);
        }
        if (is_array($value)) {
            return array_map([$this, 'normalizePayload'], $value);
        }

        return $value;
    }

    /** @param Message[] $messages */
    private function serializeMessages(array $messages): array
    {
        return array_map(
            fn (Message $m) => method_exists($m, 'toArray') ? $m->toArray() : (array) $m,
            $messages
        );
    }

    /** @param Tool[] $tools */
    private function serializeTools(array $tools): array
    {
        return array_map(fn (Tool $tool) => [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'parameters' => $tool->parametersAsArray(),
            'required' => $tool->requiredParameters(),
        ], $tools);
    }
}
