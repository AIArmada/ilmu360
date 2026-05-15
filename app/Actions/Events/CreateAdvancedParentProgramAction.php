<?php

namespace App\Actions\Events;

use App\Models\Event;
use App\Models\Institution;
use App\Models\Speaker;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

class CreateAdvancedParentProgramAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $form
     */
    public function handle(
        User $user,
        array $form,
        Carbon $programStartsAt,
        Carbon $programEndsAt,
        string $timezone,
        string $organizerType,
        string $organizerId,
        ?string $locationInstitutionId,
    ): Event {
        return DB::transaction(function () use ($user, $form, $programStartsAt, $programEndsAt, $timezone, $organizerType, $organizerId, $locationInstitutionId): Event {
            $organizerMorphClass = $this->organizerMorphClass($organizerType);
            $speakerSlugSegments = $organizerMorphClass === Speaker::class
                ? app(GenerateEventSlugAction::class)->speakerSlugSegmentsForSpeakerIds([$organizerId])
                : [];

            $parentEvent = Event::query()->create([
                'user_id' => $user->id,
                'submitter_id' => $user->id,
                'parent_event_id' => null,
                'event_structure' => 'parent_program',
                'title' => (string) $form['title'],
                'slug' => app(GenerateEventSlugAction::class)->handle(
                    (string) $form['title'],
                    $programStartsAt,
                    $timezone,
                    null,
                    $speakerSlugSegments,
                ),
                'description' => (string) ($form['description'] ?? ''),
                'starts_at' => $programStartsAt,
                'ends_at' => $programEndsAt,
                'timezone' => $timezone,
                'institution_id' => $locationInstitutionId,
                'organizer_type' => $organizerMorphClass,
                'organizer_id' => $organizerId,
                'event_type' => [(string) $form['default_event_type']],
                'event_format' => (string) $form['default_event_format'],
                'visibility' => (string) $form['visibility'],
                'schedule_kind' => 'single',
                'schedule_state' => 'active',
                'status' => 'draft',
                'is_active' => true,
            ]);

            $parentEvent->settings()->create([
                'registration_required' => (bool) $form['registration_required'],
                'registration_mode' => (string) $form['registration_mode'],
            ]);

            return $parentEvent;
        });
    }

    private function organizerMorphClass(string $organizerType): string
    {
        return $organizerType === 'institution' ? Institution::class : Speaker::class;
    }
}
