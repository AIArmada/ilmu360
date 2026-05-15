<?php

use App\Enums\TagType;
use App\Filament\Resources\Tags\Schemas\TagForm;
use App\Models\Event;
use App\Models\Tag;

it('can create a tag with type', function () {
    $tag = Tag::factory()->domain()->create();

    expect($tag->type)->toBe('domain')
        ->and($tag->type_enum)->toBe(TagType::Domain); // Access enum via accessor
});

it('can filter tags by type using scope', function () {
    Tag::factory()->domain()->count(2)->create();
    Tag::factory()->issue()->count(3)->create();
    Tag::factory()->discipline()->create();

    $domainTags = Tag::ofType(TagType::Domain)->get();
    $issueTags = Tag::ofType(TagType::Issue)->get();
    $disciplineTags = Tag::ofType('discipline')->get(); // Also test string

    expect($domainTags)->toHaveCount(2)
        ->and($issueTags)->toHaveCount(3)
        ->and($disciplineTags)->toHaveCount(1);
});

it('can use spatie native getWithType method', function () {
    Tag::factory()->domain()->count(2)->create();
    Tag::factory()->issue()->count(3)->create();

    $domainTags = Tag::getWithType('domain');

    expect($domainTags)->toHaveCount(2);
});

it('event can be tagged using spatie taggables', function () {
    $event = Event::factory()->create();
    $tag = Tag::factory()->domain()->create();

    $event->attachTag($tag);

    expect($event->tags)->toHaveCount(1)
        ->and($event->tags->first()->id)->toBe($tag->id);
});

it('can attach multiple tags to event', function () {
    $event = Event::factory()->create();
    $domainTag = Tag::factory()->domain()->create();
    $issueTag = Tag::factory()->issue()->create();

    $event->attachTags([$domainTag, $issueTag]);

    expect($event->tags)->toHaveCount(2);
});

it('can filter event tags by type', function () {
    $event = Event::factory()->create();
    $domainTag = Tag::factory()->domain()->create();
    $issueTag = Tag::factory()->issue()->create();

    $event->attachTags([$domainTag, $issueTag]);

    // Spatie's tagsWithType() expects string type
    $domainTags = $event->tagsWithType('domain');

    expect($domainTags)->toHaveCount(1)
        ->and($domainTags->first()->type)->toBe('domain');
});

it('tag type enum has expected metadata', function () {
    expect(TagType::Domain->label())->toBe('Domain')
        ->and(TagType::Domain->color())->toBe('primary')
        ->and(TagType::Domain->icon())->toBe('heroicon-o-academic-cap')
        ->and(TagType::Domain->order())->toBe(10);
});

it('resolves tag type helper descriptions for both raw and hydrated form state', function () {
    expect(TagForm::typeDescription(TagType::Domain))->toBe(TagType::Domain->description())
        ->and(TagForm::typeDescription(TagType::Issue->value))->toBe(TagType::Issue->description())
        ->and(TagForm::typeDescription(null))->toBeNull()
        ->and(TagForm::typeDescription('unknown'))->toBeNull();
});

it('orders tags within type separately', function () {
    $domainTag1 = Tag::factory()->domain()->create();
    $domainTag2 = Tag::factory()->domain()->create();
    $issueTag1 = Tag::factory()->issue()->create();

    // Both should start at 1 within their type
    $domainTag1->refresh();
    $domainTag2->refresh();
    $issueTag1->refresh();

    expect($domainTag1->order_column)->toBe(1)
        ->and($domainTag2->order_column)->toBe(2)
        ->and($issueTag1->order_column)->toBe(1);
});
