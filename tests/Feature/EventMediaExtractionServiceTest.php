<?php

use App\Livewire\Pages\SubmitEvent\Create;
use App\Services\Ai\EventMediaExtractionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    config()->set('ai.providers.openai.key', 'test-key');
});

/**
 * @return array<string, mixed>
 */
function fakeOpenAiExtractionResponse(): array
{
    return [
        'id' => 'resp_test',
        'model' => 'gpt-6.1-sol',
        'status' => 'completed',
        'output' => [
            [
                'type' => 'message',
                'status' => 'completed',
                'content' => [
                    [
                        'type' => 'output_text',
                        'text' => json_encode([
                            'title' => 'Majlis Ilmu',
                            'event_date' => '2026-11-10',
                        ]),
                    ],
                ],
            ],
        ],
        'usage' => [
            'input_tokens' => 100,
            'output_tokens' => 50,
        ],
    ];
}

it('sends livewire image uploads with the detected mime type', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(fakeOpenAiExtractionResponse()),
    ]);

    $component = Livewire::test(Create::class)
        ->set('event_source_attachment', UploadedFile::fake()->image('poster.jpg', 1200, 1600));

    /** @var UploadedFile $file */
    $file = $component->get('event_source_attachment');

    // Livewire temporary uploads report a generic client mime type even for
    // real images; the AI SDK must use the sniffed mime type instead.
    expect($file->getClientMimeType())->toBe('application/octet-stream')
        ->and($file->getMimeType())->toBe('image/jpeg');

    $payload = app(EventMediaExtractionService::class)->extract($file);

    expect($payload['title'])->toBe('Majlis Ilmu');

    $request = Http::recorded()[0][0] ?? null;

    expect($request)->not->toBeNull();

    $content = $request->data()['input'][1]['content'] ?? [];

    expect($content[1]['type'] ?? null)->toBe('input_image')
        ->and($content[1]['image_url'] ?? '')->toStartWith('data:image/jpeg;base64,');
});

it('sends livewire pdf uploads with the detected mime type', function () {
    Http::fake([
        'api.openai.com/*' => Http::response(fakeOpenAiExtractionResponse()),
    ]);

    $component = Livewire::test(Create::class)
        ->set('event_source_attachment', UploadedFile::fake()->createWithContent(
            'poster.pdf',
            "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\n%%EOF",
        ));

    /** @var UploadedFile $file */
    $file = $component->get('event_source_attachment');

    expect($file->getMimeType())->toBe('application/pdf');

    app(EventMediaExtractionService::class)->extract($file);

    $request = Http::recorded()[0][0] ?? null;

    expect($request)->not->toBeNull();

    $content = $request->data()['input'][1]['content'] ?? [];

    expect($content[1]['type'] ?? null)->toBe('input_file')
        ->and($content[1]['file_data'] ?? '')->toStartWith('data:application/pdf;base64,');
});
