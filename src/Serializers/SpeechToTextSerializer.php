<?php

namespace Rushing\PrismCassette\Serializers;

use Prism\Prism\Audio\SpeechToTextRequest;
use Prism\Prism\Audio\TextResponse;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Usage;
use Rushing\PrismCassette\Contracts\CassetteSerializer;

/**
 * Tapes Prism-native speech-to-text (provider->speechToText(): Audio\TextResponse). Ships in cassette
 * for the same reason as {@see TextToSpeechSerializer}: the types are Prism's. The audio INPUT is
 * fingerprinted (base64/url/filename) into the lookup key rather than stored — cassettes replay the
 * transcription, not the source clip.
 */
class SpeechToTextSerializer implements CassetteSerializer
{
    public function key(object $request): string
    {
        /** @var SpeechToTextRequest $request */
        $payload = [
            'type' => 'stt',
            'provider' => $request->provider(),
            'model' => $request->model(),
            'input' => $this->fingerprint($request->input()),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE));
    }

    public function provider(object $request): string
    {
        /** @var SpeechToTextRequest $request */
        return $request->provider();
    }

    public function model(object $request): string
    {
        /** @var SpeechToTextRequest $request */
        return $request->model();
    }

    public function preview(object $request): string
    {
        /** @var SpeechToTextRequest $request */
        return 'audio:'.substr($this->fingerprint($request->input()), 0, 16);
    }

    public function serialize(object $request, object $response, string $recordedAt): array
    {
        /** @var TextResponse $response */
        return [
            'recorded_at' => $recordedAt,
            'request' => [
                'type' => 'stt',
                'provider' => $this->provider($request),
                'model' => $this->model($request),
            ],
            'response' => [
                'text' => $response->text,
                'usage' => $response->usage?->toArray(),
                'additional_content' => $response->additionalContent,
            ],
        ];
    }

    public function hydrate(array $data): object
    {
        $r = $data['response'];

        return new TextResponse(
            text: $r['text'] ?? '',
            usage: isset($r['usage']) && $r['usage'] !== null
                ? new Usage(
                    promptTokens: $r['usage']['prompt_tokens'] ?? 0,
                    completionTokens: $r['usage']['completion_tokens'] ?? 0,
                )
                : null,
            additionalContent: $r['additional_content'] ?? [],
        );
    }

    public function usage(object $response): Usage
    {
        /** @var TextResponse $response */
        return $response->usage ?? new Usage(0, 0);
    }

    /** Stable identity for the source clip: hashed base64, else url, else filename. */
    private function fingerprint(Audio $audio): string
    {
        if ($audio->base64 !== null) {
            return 'b64:'.hash('sha256', $audio->base64);
        }

        if ($audio->url !== null) {
            return 'url:'.$audio->url;
        }

        return 'file:'.((string) $audio->filename());
    }
}
