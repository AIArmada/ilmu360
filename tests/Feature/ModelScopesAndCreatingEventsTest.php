<?php

use App\Models\Reference;
use App\Models\Report;
use App\Models\SavedSearch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('filters active references to verified and pending status', function () {
    $verified = Reference::factory()->verified()->create();
    $pending = Reference::factory()->pending()->create();
    $draft = Reference::factory()->create(['status' => 'draft']);

    $active = Reference::active()->get();

    expect($active->pluck('id'))->toContain((string) $verified->getKey())
        ->toContain((string) $pending->getKey())
        ->not->toContain((string) $draft->getKey());
});

it('sets reporter_type to user morph class on report creation', function () {
    $user = User::factory()->create();

    $report = Report::factory()->create([
        'reporter_type' => null,
        'reporter_id' => $user->id,
    ]);

    expect($report->reporter_type)->toBe((new User)->getMorphClass());
});

it('does not override explicit reporter_type on report creation', function () {
    $report = Report::factory()->create([
        'reporter_type' => 'custom_bot',
        'reporter_id' => null,
    ]);

    expect($report->reporter_type)->toBe('custom_bot');
});

it('sets user_type to user morph class on saved search creation', function () {
    $user = User::factory()->create();

    $search = SavedSearch::factory()->create([
        'user_type' => null,
        'user_id' => $user->id,
    ]);

    expect($search->user_type)->toBe((new User)->getMorphClass());
});

it('does not override explicit user_type on saved search creation', function () {
    $search = SavedSearch::factory()->create([
        'user_type' => 'custom_type',
    ]);

    expect($search->user_type)->toBe('custom_type');
});
