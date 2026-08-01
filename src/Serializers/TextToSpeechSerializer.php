<?php

namespace Rushing\PrismCassette\Serializers;

use Prism\Prism\Audio\AudioResponse;
use Prism\Prism\Audio\TextToSpeechRequest;
use Prism\Prism\ValueObjects\GeneratedAudio;
use Prism\Prism\ValueObjects\Usage;
use Rushing\PrismCassette\Contracts\CassetteSerializer;

/**
 * Tapes Prism-native text-to-speech (provider->textToSpeech(): AudioResponse). Ships in cassette
 * because the request/response types are Prism's, and cassette already depends on Prism — the audio
 * bytes are stored base64 exactly as the driver returns them, so replay is byte-identical. TTS
 * reports no token usage, so {@see Usage()} is always zero.
 */
class TextToSpeechSerializer implements CassetteSerializer
{
    public function key(object $request): string
    {
        /** @var TextToSpeechRequest $request */
        $payload = [
            'type' => 'tts',
            'provider' => $request->provider(),
            'model' => $request->model(),
            'input' => $request->input(),
            'voice' => $request->voice(),
            'provider_options' => method_exists($request, 'providerOptions') ? $request->providerOptions() : [],
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function provider(object $request): string
    {
        /** @var TextToSpeechRequest $request */
        return $request->provider();
    }

    public function model(object $request): string
    {
        /** @var TextToSpeechRequest $request */
        return $request->model();
    }

    public function preview(object $request): string
    {
        /** @var TextToSpeechRequest $request */
        return substr($request->input(), 0, 80);
    }

    public function serialize(object $request, object $response, string $recordedAt): array
    {
        /** @var TextToSpeechRequest $request */
        /** @var AudioResponse $response */
        return [
            'recorded_at' => $recordedAt,
            'request' => [
                'type' => 'tts',
                'provider' => $request->provider(),
                'model' => $request->model(),
                'voice' => $request->voice(),
                'input' => $request->input(),
            ],
            'response' => [
                'audio' => [
                    'base64' => $response->audio->base64,
                    'type' => $response->audio->type,
                ],
                'additional_content' => $response->additionalContent,
            ],
        ];
    }

    public function hydrate(array $data): object
    {
        $r = $data['response'];

        return new AudioResponse(
            audio: new GeneratedAudio(
                base64: $r['audio']['base64'] ?? null,
                type: $r['audio']['type'] ?? null,
            ),
            additionalContent: $r['additional_content'] ?? [],
        );
    }

    public function usage(object $response): Usage
    {
        return new Usage(0, 0);
    }
}
