<?php

use Prism\Prism\Audio\AudioResponse;
use Prism\Prism\Audio\SpeechToTextRequest;
use Prism\Prism\Audio\TextResponse as SttTextResponse;
use Prism\Prism\Audio\TextToSpeechRequest;
use Prism\Prism\Providers\Provider as ProviderBase;
use Prism\Prism\ValueObjects\GeneratedAudio;
use Prism\Prism\ValueObjects\Media\Audio;
use Prism\Prism\ValueObjects\Usage;
use Rushing\PrismCassette\CassetteProvider;
use Rushing\PrismCassette\Drivers\FileCassetteStore;
use Rushing\PrismCassette\Exceptions\CassetteMissException;

// ── TTS: record → replay round-trip (binary audio, byte-identical) ─────────

it('records a text-to-speech call then replays the audio without calling the delegate', function () {
    $store = new FileCassetteStore(tempAudioDir());
    $bytes = base64_encode('FAKE-MP3-BYTES-🎧');

    $recorder = new CassetteProvider(fakeTtsDelegate($bytes, 'audio/mpeg'), $store, 'record', 'elevenlabs');
    $recorded = $recorder->textToSpeech(ttsRequest('Hello world'));

    $player = new CassetteProvider(explodingTtsDelegate(), $store, 'replay', 'elevenlabs');
    $replayed = $player->textToSpeech(ttsRequest('Hello world'));

    expect($recorded->audio->base64)->toBe($bytes)
        ->and($replayed->audio->base64)->toBe($bytes)
        ->and($replayed->audio->type)->toBe('audio/mpeg');
});

it('throws a CassetteMissException on a TTS replay miss', function () {
    $store = new FileCassetteStore(tempAudioDir());
    $player = new CassetteProvider(explodingTtsDelegate(), $store, 'replay', 'elevenlabs');

    $player->textToSpeech(ttsRequest('nothing recorded for this'));
})->throws(CassetteMissException::class);

it('is content-addressed: same TTS input reuses one cassette', function () {
    $store = new FileCassetteStore($dir = tempAudioDir());
    $provider = new CassetteProvider(fakeTtsDelegate(base64_encode('x'), 'audio/mpeg'), $store, 'record', 'elevenlabs');

    $provider->textToSpeech(ttsRequest('same'));
    $provider->textToSpeech(ttsRequest('same')); // hit

    $files = glob($dir.'/**/**/*.json') ?: [];
    expect($files)->toHaveCount(1);
});

it('keys TTS by voice — a different voice is a different cassette', function () {
    $store = new FileCassetteStore($dir = tempAudioDir());
    $provider = new CassetteProvider(fakeTtsDelegate(base64_encode('x'), 'audio/mpeg'), $store, 'record', 'elevenlabs');

    $provider->textToSpeech(ttsRequest('same text', voice: 'rachel'));
    $provider->textToSpeech(ttsRequest('same text', voice: 'adam'));

    $files = glob($dir.'/**/**/*.json') ?: [];
    expect($files)->toHaveCount(2);
});

// ── STT: record → replay round-trip ────────────────────────────────────────

it('records a speech-to-text call then replays the transcript', function () {
    $store = new FileCassetteStore(tempAudioDir());

    $recorder = new CassetteProvider(fakeSttDelegate('the transcript'), $store, 'record', 'elevenlabs');
    $recorded = $recorder->speechToText(sttRequest('AUDIO-A'));

    $player = new CassetteProvider(explodingSttDelegate(), $store, 'replay', 'elevenlabs');
    $replayed = $player->speechToText(sttRequest('AUDIO-A'));

    expect($recorded->text)->toBe('the transcript')
        ->and($replayed->text)->toBe('the transcript');
});

// ── Passthrough: no store, delegate runs, nothing recorded ─────────────────

it('passes TTS straight through in passthrough mode', function () {
    // Direct construction with mode=passthrough → resolve() returns [null,null,null] → live.
    $provider = new CassetteProvider(fakeTtsDelegate(base64_encode('live'), 'audio/mpeg'), null, 'passthrough', 'elevenlabs');

    $response = $provider->textToSpeech(ttsRequest('anything'));

    expect($response->audio->base64)->toBe(base64_encode('live'));
});

// ── Helpers ────────────────────────────────────────────────────────────────

function tempAudioDir(): string
{
    return sys_get_temp_dir().'/cassette-audio-'.uniqid();
}

function ttsRequest(string $input, string $voice = 'rachel'): TextToSpeechRequest
{
    return new TextToSpeechRequest(
        model: 'eleven_multilingual_v2',
        providerKey: 'elevenlabs',
        input: $input,
        voice: $voice,
        clientOptions: [],
        clientRetry: [0],
    );
}

function sttRequest(string $bytes): SpeechToTextRequest
{
    return new SpeechToTextRequest(
        model: 'scribe_v1',
        providerKey: 'elevenlabs',
        input: Audio::fromBase64(base64_encode($bytes), 'audio/mpeg'),
        clientOptions: [],
        clientRetry: [0],
    );
}

function fakeTtsDelegate(string $base64, string $type): ProviderBase
{
    return new class($base64, $type) extends ProviderBase
    {
        public function __construct(private string $base64, private string $type) {}

        #[Override]
        public function textToSpeech(TextToSpeechRequest $request): AudioResponse
        {
            return new AudioResponse(new GeneratedAudio($this->base64, $this->type));
        }
    };
}

function explodingTtsDelegate(): ProviderBase
{
    return new class extends ProviderBase
    {
        #[Override]
        public function textToSpeech(TextToSpeechRequest $request): AudioResponse
        {
            throw new RuntimeException('Delegate must not be called on a replay hit.');
        }
    };
}

function fakeSttDelegate(string $text): ProviderBase
{
    return new class($text) extends ProviderBase
    {
        public function __construct(private string $text) {}

        #[Override]
        public function speechToText(SpeechToTextRequest $request): SttTextResponse
        {
            return new SttTextResponse($this->text, new Usage(0, 0));
        }
    };
}

function explodingSttDelegate(): ProviderBase
{
    return new class extends ProviderBase
    {
        #[Override]
        public function speechToText(SpeechToTextRequest $request): SttTextResponse
        {
            throw new RuntimeException('Delegate must not be called on a replay hit.');
        }
    };
}
