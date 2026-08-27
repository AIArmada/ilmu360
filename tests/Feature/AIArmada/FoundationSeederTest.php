<?php

use AIArmada\Events\Models\EventRole;
use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use App\Contracts\EventCategoryCatalog;
use App\Enums\EventKeyPersonRole;
use App\Enums\TaxonomyTerm\DisciplineTermCode;
use App\Enums\TaxonomyTerm\DomainTermCode;
use Database\Seeders\AIArmada\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

it('seeds EventRole rows from EventKeyPersonRole + organizer', function (): void {
    seed(FoundationSeeder::class);

    expect(EventRole::query()->count())->toBe(count(EventKeyPersonRole::cases()) + 1);

    $organizer = EventRole::query()->where('code', 'organizer')->first();
    expect($organizer)->not->toBeNull()
        ->and($organizer->name)->toBe('Organizer');

    $person = EventRole::query()->where('code', 'speaker')->first();
    expect($person)->not->toBeNull()
        ->and($person->name)->toBe(EventKeyPersonRole::Speaker->getLabel());
});

it('seeds flat activity-first EventTaxonomy and EventTerm categories', function (): void {
    seed(FoundationSeeder::class);

    $taxonomy = EventTaxonomy::query()->where('code', EventCategoryCatalog::TAXONOMY_CODE)->first();
    expect($taxonomy)->not->toBeNull()
        ->and($taxonomy->name)->toBe('Event Category')
        ->and($taxonomy->is_hierarchical)->toBeFalse()
        ->and($taxonomy->is_active)->toBeTrue();

    $terms = EventTerm::query()
        ->where('event_taxonomy_id', $taxonomy->id)
        ->orderBy('sort_order')
        ->get();

    expect($terms->whereNull('parent_id')->count())->toBe(8)
        ->and($terms->whereNotNull('parent_id')->count())->toBe(0);

    expect($terms->where('code', 'kuliah_ceramah')->first())->not->toBeNull()
        ->and($terms->where('code', 'kelas_kursus')->first())->not->toBeNull()
        ->and($terms->where('code', 'ilmu')->first())->toBeNull();
});

it('seeds broad optional event topics', function (): void {
    seed(FoundationSeeder::class);

    $taxonomy = EventTaxonomy::query()->where('code', 'domain')->firstOrFail();

    expect(EventTerm::query()
        ->where('event_taxonomy_id', $taxonomy->getKey())
        ->orderBy('sort_order')
        ->pluck('name', 'code')
        ->all()
    )->toBe([
        'agama-kerohanian' => 'Agama & Kerohanian',
        'pendidikan' => 'Pendidikan',
        'sains-matematik' => 'Sains & Matematik',
        'teknologi-it' => 'Teknologi & IT',
        'kerjaya-kemahiran' => 'Kerjaya & Kemahiran',
        'kesihatan' => 'Kesihatan',
        'keluarga-masyarakat' => 'Keluarga & Masyarakat',
        'lain-lain' => 'Lain-lain / Tulis sendiri',
    ])
        ->and($taxonomy->is_hierarchical)->toBeFalse();
});

it('is idempotent (safe to run multiple times)', function (): void {
    seed(FoundationSeeder::class);
    $firstCount = EventRole::query()->count();

    seed(FoundationSeeder::class);

    expect(EventRole::query()->count())->toBe($firstCount);
});

it('links discipline terms to the religious domain for cascade filtering', function (): void {
    seed(FoundationSeeder::class);

    $domainTaxonomyId = EventTaxonomy::query()->where('code', 'domain')->value('id');
    $religiousId = EventTerm::query()
        ->where('event_taxonomy_id', $domainTaxonomyId)
        ->where('code', DomainTermCode::AgamaKerohanian->value)
        ->value('id');
    $disciplineTaxonomyId = EventTaxonomy::query()->where('code', 'discipline')->value('id');

    expect($religiousId)->not->toBeNull();

    $countUnder = static function (?string $domainId): int {
        return EventTerm::query()
            ->where('event_taxonomy_id', EventTaxonomy::query()->where('code', 'discipline')->value('id'))
            ->where('is_active', true)
            ->where(function ($query) use ($domainId): void {
                $query->whereJsonContains('metadata->domain_ids', $domainId)
                    ->orWhereNull('metadata->domain_ids');
            })
            ->count();
    };

    expect($countUnder($religiousId))->toBe(count(DisciplineTermCode::cases()));

    $otherDomainId = EventTerm::query()
        ->where('event_taxonomy_id', $domainTaxonomyId)
        ->where('code', 'pendidikan')
        ->value('id');

    expect($countUnder($otherDomainId))->toBe(0);
});
