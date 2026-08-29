<?php

use App\Models\Reference;
use Livewire\Livewire;

use function Pest\Laravel\get;

beforeEach(function (): void {
    config()->set('scout.driver', 'null');
});

it('renders the public reference index hero and search copy', function () {
    app()->setLocale('ms');

    get('/rujukan')
        ->assertSuccessful()
        ->assertSee(__('Sources of'))
        ->assertSee(__('Knowledge & Guidance'))
        ->assertSee(__('Search references...'));
});

it('searches published verified and pending references by title', function () {
    Reference::factory()->create([
        'title' => 'Riyadhus Solihin Terjemahan',
        'status' => 'verified',
    ]);

    Reference::factory()->create([
        'title' => 'Bulughul Maram',
        'status' => 'verified',
    ]);

    get('/rujukan?search='.urlencode('riyadhus'))
        ->assertSuccessful()
        ->assertSee('Riyadhus Solihin Terjemahan')
        ->assertDontSee('Bulughul Maram');
});

it('only lists published verified and pending references on the public index', function () {
    Reference::factory()->create([
        'title' => 'Rujukan Sah Paparan',
        'status' => 'verified',
    ]);

    Reference::factory()->create([
        'title' => 'Rujukan Menunggu Semakan',
        'status' => 'pending',
    ]);

    Reference::factory()->create([
        'title' => 'Rujukan Tidak Aktif',
        'status' => 'inactive',
    ]);

    Reference::factory()->pending()->unpublished()->create([
        'title' => 'Rujukan Belum Diterbitkan',
    ]);

    get('/rujukan')
        ->assertSuccessful()
        ->assertSee('Rujukan Sah Paparan')
        ->assertSee('Rujukan Menunggu Semakan')
        ->assertDontSee('Rujukan Tidak Aktif')
        ->assertDontSee('Rujukan Belum Diterbitkan');
});

it('lists pending references in the directory and includes them in search', function () {
    Reference::factory()->create([
        'title' => 'Rujukan Belum Disahkan',
        'status' => 'pending',
    ]);

    Reference::factory()->pending()->unpublished()->create([
        'title' => 'Rujukan Pending Belum Terbit',
    ]);

    get('/rujukan')
        ->assertSuccessful()
        ->assertSee('Rujukan Belum Disahkan');

    Livewire::test('pages.references.index', ['search' => 'Rujukan Belum Disahkan'])
        ->assertSee('Rujukan Belum Disahkan');

    Livewire::test('pages.references.index', ['search' => 'Rujukan Pending Belum Terbit'])
        ->assertDontSee('Rujukan Pending Belum Terbit');
});

it('shows the reference empty state and clear icon button', function () {
    get('/rujukan?search=zzzzzzzzz')
        ->assertSuccessful()
        ->assertSee(__('No references found'))
        ->assertSee(__('We couldn\'t find any references matching your search.'))
        ->assertSee('aria-label="Clear search"', false);
});

it('shows the total reference count at the bottom of the index', function () {
    $searchPrefix = 'Jumlah Rujukan Ujian';

    Reference::factory()->count(2)->create([
        'title' => $searchPrefix,
        'status' => 'verified',
    ]);

    get('/rujukan?search='.urlencode($searchPrefix))
        ->assertSuccessful()
        ->assertSee('Direktori Rujukan')
        ->assertSee('Jumlah rujukan: 2');
});
