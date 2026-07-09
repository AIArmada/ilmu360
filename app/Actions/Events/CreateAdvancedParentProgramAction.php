<?php

namespace App\Actions\Events;

use AIArmada\Events\Enums\RegistrationMode;
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
        Institution|Speaker $primaryOrganizer,
        ?string $locationInstitutionId,
    ): Event {
        return DB::transaction(function () use ($user, $form, $programStartsAt, $programEndsAt, $timezone, $primaryOrganizer, $locationInstitutionId): Event {
            $speakerSlugSegments = $primaryOrganizer instanceof Speaker
                ? app(GenerateEventSlugAction::class)->speakerSlugSegmentsForSpeakerIds([(string) $primaryOrganizer->getKey()])
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
                'event_type' => [(string) $form['default_event_type']],
                'event_format' => (string) $form['default_event_format'],
                'visibility' => (string) $form['visibility'],
                'registration_mode' => ! empty($form['registration_required'])
                    ? RegistrationMode::Required->value
                    : RegistrationMode::None->value,
                'schedule_kind' => 'single',
                'schedule_state' => 'active',
                'status' => 'draft',

            ]);

            $parentEvent->accessPolicy()->create([
                'registration_required' => (bool) $form['registration_required'],
                'walk_in_allowed' => ! (bool) $form['registration_required'],
            ]);

            $parentEvent->setPrimaryOrganizer($primaryOrganizer);

            return $parentEvent;
        });
    }
}
