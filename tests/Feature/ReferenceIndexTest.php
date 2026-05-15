<?php

use App\Models\Reference;

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

it('searches public verified references by title', function () {
    Reference::factory()->create([
        'title' => 'Riyadhus Solihin Terjemahan',
        'status' => 'verified',
        'is_active' => true,
    ]);

    Reference::factory()->create([
        'title' => 'Bulughul Maram',
        'status' => 'verified',
        'is_active' => true,
    ]);

    get('/rujukan?search='.urlencode('riyadhus'))
        ->assertSuccessful()
        ->assertSee('Riyadhus Solihin Terjemahan')
        ->assertDontSee('Bulughul Maram');
});

it('only lists active verified references on the public index', function () {
    Reference::factory()->create([
        'title' => 'Rujukan Sah Paparan',
        'status' => 'verified',
        'is_active' => true,
    ]);

    Reference::factory()->create([
        'title' => 'Rujukan Menunggu Semakan',
        'status' => 'pending',
        'is_active' => true,
    ]);

    Reference::factory()->create([
        'title' => 'Rujukan Tidak Aktif',
        'status' => 'verified',
        'is_active' => false,
    ]);

    get('/rujukan')
        ->assertSuccessful()
        ->assertSee('Rujukan Sah Paparan')
        ->assertDontSee('Rujukan Menunggu Semakan')
        ->assertDontSee('Rujukan Tidak Aktif');
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
        'is_active' => true,
    ]);

    get('/rujukan?search='.urlencode($searchPrefix))
        ->assertSuccessful()
        ->assertSee('Direktori Rujukan')
        ->assertSee('Jumlah rujukan: 2');
});
