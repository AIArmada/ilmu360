<?php

use AIArmada\Authz\Models\Role;
use App\Models\Person;
use App\Models\User;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Uid\Ulid;

it('gives simultaneous Filament uploads distinct storage filenames', function (): void {
    Storage::fake('local');

    $upload = SpatieMediaLibraryFileUpload::make('gallery')
        ->model(Person::factory()->make(['name' => 'Same person']));
    Storage::disk('local')->put(
        FileUploadConfiguration::path('photo.png', false).'.json',
        json_encode(['name' => 'photo.png'], JSON_THROW_ON_ERROR),
    );
    $file = new TemporaryUploadedFile('photo.png', 'local');

    Str::createUlidsUsingSequence([
        new Ulid('01ARZ3NDEKTSV4RRFFQ69G5FAV'),
        new Ulid('01ARZ3NDEKTSV4RRFFQ69G5FAW'),
    ]);

    try {
        $firstName = $upload->getUploadedFileNameForStorage($file);
        $secondName = $upload->getUploadedFileNameForStorage($file);
    } finally {
        Str::createUlidsNormally();
    }

    expect($firstName)->not->toBe($secondName);
});

it('loads the shared Filament file upload component asset', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('contributions.submit-person'))
        ->assertOk()
        ->assertSee('/js/filament/forms/components/file-upload.js', false)
        ->assertSee('/js/aiarmada/commerce-support/file-upload-tabs.js', false);

    expect(is_file(public_path('js/filament/forms/components/file-upload.js')))->toBeTrue();
});

it('loads the shared FileUpload lifecycle workaround in the admin panel', function (): void {
    setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $role = Role::firstOrCreate([
        'name' => 'super_admin',
        'guard_name' => 'web',
    ]);
    $user = User::factory()->create();
    $user->assignRole($role);
    $person = Person::factory()->create();

    $this->actingAs($user)
        ->get(route('filament.admin.resources.persons.edit', $person))
        ->assertOk()
        ->assertSee('/js/aiarmada/commerce-support/file-upload-tabs.js', false);

    expect(is_file(public_path('js/aiarmada/commerce-support/file-upload-tabs.js')))->toBeTrue();
});
