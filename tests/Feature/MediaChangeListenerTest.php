<?php

use App\Models\Institution;
use App\Platform\Media\MediaChangeListener;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

final class RecordingMediaChangeListener implements MediaChangeListener
{
    /** @var list<string> */
    public array $changes = [];

    public function mediaChanged(Media $media, string $change): void
    {
        $this->changes[] = $change;
    }
}

beforeEach(function (): void {
    Storage::fake('public');
    config()->set('media-library.disk_name', 'public');
});

it('dispatches media changes to tagged listeners', function (): void {
    $listener = new RecordingMediaChangeListener;

    app()->instance(RecordingMediaChangeListener::class, $listener);
    app()->tag([RecordingMediaChangeListener::class], MediaChangeListener::TAG);

    $institution = Institution::factory()->create(['name' => 'Listener Institution']);
    $media = $institution->addMedia(fakeGeneratedImageUpload('logo.png', 800, 800))->toMediaCollection('logo');

    $media->name = 'Renamed logo';
    $media->save();
    $media->delete();

    expect($listener->changes)->toContain('created')
        ->toContain('updated')
        ->toContain('deleted')
        ->and($listener->changes[0])->toBe('created')
        ->and($listener->changes[array_key_last($listener->changes)])->toBe('deleted');
});
