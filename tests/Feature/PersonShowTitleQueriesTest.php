<?php

use AIArmada\Persons\Enums\AssignmentStatus;
use AIArmada\Persons\Enums\TitleUsagePosition;
use AIArmada\Persons\Models\Title;
use AIArmada\Persons\Models\TitleAssignment;
use AIArmada\Persons\Models\TitleCategory;
use App\Shared\Events\Enums\EventKeyPersonRole;
use App\Models\Event;
use App\Models\EventKeyPerson;
use App\Models\Person;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

it('renders active speaker titles on person event cards without per-speaker title queries', function () {
    $person = Person::factory()->create(['name' => 'Featured Speaker', 'status' => 'verified']);
    $speakers = Person::factory()->count(4)->create(['status' => 'verified']);
    $categoryId = TitleCategory::query()->where('code', 'academic')->firstOrFail()->getKey();
    TitleAssignment::query()->whereIn('titleable_id', $speakers->pluck('id'))->delete();
    $beforeTitle = Title::query()->create([
        'category_id' => $categoryId,
        'sort_order' => 1001, 'name' => 'Professor', 'short_form' => 'Prof.', 'usage_position' => TitleUsagePosition::BeforeName,
    ]);
    $afterTitle = Title::query()->create([
        'category_id' => $categoryId,
        'sort_order' => 1001, 'name' => 'Doctor of Philosophy', 'short_form' => 'PhD', 'usage_position' => TitleUsagePosition::AfterName,
    ]);
    $revokedTitle = Title::query()->create([
        'category_id' => $categoryId,
        'sort_order' => 1002, 'name' => 'Revoked Honorific', 'short_form' => 'REVOKED', 'usage_position' => TitleUsagePosition::BeforeName,
    ]);

    foreach ($speakers as $speaker) {
        foreach ([$beforeTitle, $afterTitle, $revokedTitle] as $title) {
            TitleAssignment::query()->create([
                'titleable_type' => $speaker->getMorphClass(),
                'titleable_id' => $speaker->getKey(),
                'title_id' => $title->getKey(),
                'status' => $title->is($revokedTitle) ? AssignmentStatus::Revoked : AssignmentStatus::Active,
            ]);
        }
    }

    foreach (range(1, 2) as $day) {
        $event = Event::factory()->create([
            'title' => "Titled Speakers Event {$day}",
            'status' => 'approved', 'visibility' => 'public', 'published_at' => now(),
            'starts_at' => now()->addDays($day),
        ]);

        foreach ($speakers->concat([$person]) as $speaker) {
            EventKeyPerson::query()->create([
                'event_id' => $event->getKey(),
                'involveable_type' => $speaker->getMorphClass(),
                'involveable_id' => $speaker->getKey(),
                'role_code' => EventKeyPersonRole::Speaker->value,
                'sort_order' => 1, 'visibility' => 'public',
            ]);
        }
    }

    $titleQueries = [];
    DB::listen(function (QueryExecuted $query) use (&$titleQueries): void {
        if (preg_match('/\bfrom\s+["`]?title_assignments["`]?/i', $query->sql)) {
            $titleQueries[] = $query->sql;
        }
    });

    $response = $this->get(route('persons.show', $person))
        ->assertSuccessful()
        ->assertSeeText('Titled Speakers Event 1')
        ->assertSeeText('Titled Speakers Event 2')
        ->assertDontSeeText('REVOKED');

    foreach ($speakers as $speaker) {
        if ($speaker->is($person)) {
            continue;
        }

        $response->assertSeeText("Prof. {$speaker->name}, PhD");
    }

    expect($titleQueries)->toHaveCount(3);
});
