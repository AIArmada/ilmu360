<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\ModerationReview;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ModerationReviewSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (ModerationReview::query()->exists()) {
            return;
        }

        $moderatorIds = User::query()->pluck('id')->toArray();

        if (empty($moderatorIds)) {
            return;
        }

        ModerationReview::unsetEventDispatcher();

        try {
            DB::transaction(function () use ($moderatorIds): void {
                $events = Event::query()
                    ->whereIn('status', ['approved', 'rejected', 'pending'])
                    ->select(['id', 'status'])
                    ->get();

                $reviewsToInsert = [];

                foreach ($events as $event) {
                    $decision = match ($event->status) {
                        'approved' => 'approved',
                        'rejected' => 'rejected',
                        default => 'needs_changes',
                    };

                    $reviewsToInsert[] = array_merge(
                        ModerationReview::factory()->make([
                            'event_id' => $event->id,
                            'moderator_id' => $moderatorIds[array_rand($moderatorIds)],
                            'decision' => $decision,
                        ])->toArray(),
                        [
                            'id' => (string) Str::uuid(),
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]
                    );
                }

                foreach (array_chunk($reviewsToInsert, 200) as $chunk) {
                    ModerationReview::insert($chunk);
                }
            });
        } finally {
            ModerationReview::setEventDispatcher(app('events'));
        }
    }
}
