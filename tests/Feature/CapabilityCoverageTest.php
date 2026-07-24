<?php

use Prism\Prism\Providers\Provider as ProviderBase;
use Prism\Prism\ValueObjects\Usage;
use Rushing\PrismCassette\CassetteManager;
use Rushing\PrismCassette\CassetteProvider;
use Rushing\PrismCassette\Contracts\CassetteSerializer;

/**
 * The base Prism Provider declares every capability as a concrete method that throws "unsupported".
 * Because they're concrete on the parent, PHP never routes them to __call — so CassetteProvider MUST
 * override each capability it means to intercept, or that capability dies with
 * "…not supported by CassetteProvider" the moment it's called through an armed provider (which is
 * exactly how TTS regressed). This guard fails loudly when a capability we claim to support is left
 * inherited — including the day a Prism bump adds one to this list.
 */
it('overrides every capability cassette claims to intercept', function () {
    $intercepted = ['text', 'structured', 'embeddings', 'stream', 'textToSpeech', 'speechToText'];

    foreach ($intercepted as $method) {
        $declaring = (new ReflectionMethod(CassetteProvider::class, $method))->getDeclaringClass()->getName();

        expect($declaring)->toBe(
            CassetteProvider::class,
            "CassetteProvider must override {$method}() — inherited from the base Provider it would throw 'unsupported'."
        );
    }
});

it('documents the capabilities cassette deliberately does NOT tape', function () {
    // images/moderation are not recorded yet. If Prism keeps them and you decide to tape one,
    // move it into the intercepted list above and add a serializer. This test simply pins the
    // current, intentional gap so it's a decision, not an oversight.
    $notTaped = ['images', 'moderation'];

    foreach ($notTaped as $method) {
        $declaring = (new ReflectionMethod(ProviderBase::class, $method))->getDeclaringClass()->getName();
        expect($declaring)->toBe(ProviderBase::class);
    }
});

// ── The extension seam ─────────────────────────────────────────────────────

it('ships Prism-native audio serializers on the manager', function () {
    $manager = app(CassetteManager::class);

    expect($manager->serializer('tts'))->toBeInstanceOf(CassetteSerializer::class)
        ->and($manager->serializer('stt'))->toBeInstanceOf(CassetteSerializer::class)
        ->and($manager->serializer('nonexistent'))->toBeNull();
});

it('lets a downstream capability register its own serializer (open for extension)', function () {
    $manager = app(CassetteManager::class);

    $custom = new class implements CassetteSerializer
    {
        public function key(object $request): string
        {
            return 'k';
        }

        public function provider(object $request): string
        {
            return 'prismplus';
        }

        public function model(object $request): string
        {
            return 'rerank-v3';
        }

        public function preview(object $request): string
        {
            return 'p';
        }

        public function serialize(object $request, object $response, string $recordedAt): array
        {
            return ['recorded_at' => $recordedAt];
        }

        public function hydrate(array $data): object
        {
            return (object) $data;
        }

        public function usage(object $response): Usage
        {
            return new Usage(0, 0);
        }
    };

    $manager->registerSerializer('rerank', $custom);

    expect($manager->serializer('rerank'))->toBe($custom);
});
