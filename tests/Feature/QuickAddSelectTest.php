<?php

use App\Forms\Components\Select;
use App\Models\Event;
use App\Models\Reference;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('prepends a quick-add option when a relationship search has no exact match', function () {
    Reference::factory()->create(['title' => 'Riyadhus Solihin Syarah']);

    $component = makeQuickAddSelect()
        ->useTestRelationship(Event::factory()->create()->references(), 'title')
        ->getSearchResultsUsing(fn (?string $search): array => Reference::query()
            ->where('title', 'like', '%'.$search.'%')
            ->pluck('title', 'id')
            ->all())
        ->quickAdd();

    $results = $component->getSearchResults('Riyadhus Solihin');

    $expectedLabel = __('quick_add.add', ['term' => 'Riyadhus Solihin']);

    expect($results)->toHaveKey('__quick_add__Riyadhus Solihin')
        ->and($results['__quick_add__Riyadhus Solihin'])->toBe($expectedLabel)
        ->and(array_values($results))->toContain('Riyadhus Solihin Syarah')
        ->and($results)->toHaveCount(2);
});

it('does not prepend a quick-add option when the relationship search already matches exactly', function () {
    Reference::factory()->create(['title' => 'Riyadhus Solihin']);

    $component = makeQuickAddSelect()
        ->useTestRelationship(Event::factory()->create()->references(), 'title')
        ->getSearchResultsUsing(fn (?string $search): array => Reference::query()
            ->where('title', 'like', '%'.$search.'%')
            ->pluck('title', 'id')
            ->all())
        ->quickAdd();

    $results = $component->getSearchResults('riyadhus solihin');

    expect($results)->not->toHaveKey('__quick_add__riyadhus solihin')
        ->and(array_values($results))->toContain('Riyadhus Solihin');
});

it('creates and selects a related record when the quick-add option is chosen', function () {
    $component = makeQuickAddSelect()
        ->useTestRelationship(Event::factory()->create()->references(), 'title')
        ->quickAdd();

    $component->state(['__quick_add__Madarij al-Salikin']);
    $component->runAfterStateUpdatedHooks();

    $reference = Reference::query()->where('title', 'Madarij al-Salikin')->first();

    expect($reference)->not->toBeNull()
        ->and($component->getState())->toBe([(string) $reference?->getKey()]);
});

function makeQuickAddSelect(): Select
{
    return new class('references') extends Select
    {
        protected ?BelongsToMany $testRelationship = null;

        protected ?string $testRelationshipTitleAttribute = null;

        protected mixed $testState = null;

        public function useTestRelationship(BelongsToMany $relationship, string $titleAttribute): static
        {
            $this->testRelationship = $relationship;
            $this->testRelationshipTitleAttribute = $titleAttribute;

            return $this;
        }

        public function hasRelationship(): bool
        {
            return $this->testRelationship instanceof BelongsToMany;
        }

        public function getRelationship(): ?BelongsToMany
        {
            return $this->testRelationship;
        }

        public function getRelationshipTitleAttribute(): ?string
        {
            return $this->testRelationshipTitleAttribute;
        }

        public function getState(): mixed
        {
            return $this->testState;
        }

        public function state(mixed $state): static
        {
            $this->testState = $state;

            return $this;
        }

        public function runAfterStateUpdatedHooks(): void
        {
            foreach ($this->afterStateUpdated as $callback) {
                $callback($this, $this->testState);
            }
        }
    };
}
