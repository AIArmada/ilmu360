<?php

use App\Enums\ReferenceType;
use App\Models\Reference;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('auto-generates a slug when slug is blank on save', function () {
    $reference = Reference::factory()->create([
        'slug' => '',
        'title' => 'Kitab Al-Hikam',
    ]);

    expect($reference->slug)->not->toBeEmpty()
        ->and($reference->slug)->toContain('kitab-al-hikam');
});

it('keeps existing slug when slug is already set', function () {
    $reference = Reference::factory()->create([
        'slug' => 'my-custom-slug',
        'title' => 'Kitab Al-Hikam',
    ]);

    expect($reference->slug)->toBe('my-custom-slug');
});

it('sets verified_by to the authenticated user when status changes to verified', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $reference = Reference::factory()->pending()->create([
        'status' => 'pending',
    ]);

    expect($reference->verified_by)->toBeNull();

    $reference->update(['status' => 'verified']);

    expect($reference->fresh()->verified_by)->toBe((string) $user->getKey());
});

it('does not set verified_by when status is not verified', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $reference = Reference::factory()->create([
        'status' => 'pending',
        'verified_by' => null,
    ]);

    expect($reference->verified_by)->toBeNull();
});

it('normalizes part fields for book references with a parent', function () {
    $parent = Reference::factory()->create([
        'type' => ReferenceType::Book->value,
    ]);

    $reference = Reference::factory()->create([
        'type' => ReferenceType::Book->value,
        'parent_id' => $parent->id,
        'part_type' => 'jilid',
        'part_number' => '2',
        'part_label' => null,
    ]);

    expect($reference->parent_id)->toBe((string) $parent->getKey())
        ->and($reference->part_type)->toBe('jilid')
        ->and($reference->part_number)->toBe(2);
});

it('clears part fields for non-book references even when parent_id is set', function () {
    $parent = Reference::factory()->create([
        'type' => ReferenceType::Book->value,
    ]);

    $reference = Reference::factory()->create([
        'type' => ReferenceType::Article->value,
        'parent_id' => $parent->id,
        'part_type' => 'jilid',
        'part_number' => 2,
        'part_label' => 'My Label',
    ]);

    expect($reference->parent_id)->toBeNull()
        ->and($reference->part_type)->toBeNull()
        ->and($reference->part_number)->toBeNull()
        ->and($reference->part_label)->toBeNull();
});

it('clears part fields when parent_id is blank for book references', function () {
    $reference = Reference::factory()->create([
        'type' => ReferenceType::Book->value,
        'parent_id' => null,
        'part_type' => 'jilid',
        'part_number' => 2,
    ]);

    expect($reference->parent_id)->toBeNull()
        ->and($reference->part_type)->toBeNull()
        ->and($reference->part_number)->toBeNull();
});
