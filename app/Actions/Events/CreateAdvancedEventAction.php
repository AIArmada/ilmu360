<?php

namespace App\Actions\Events;

use AIArmada\Events\Enums\RegistrationMode;
use AIArmada\Events\Enums\ScheduleKind;
use App\Enums\TimingMode;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Person;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Creates the event container and its first scheduled occurrence.
 *
 * Sessions are deliberately created beneath that occurrence; this action never
 * models an event as the child of another event.
 */
class CreateAdvancedEventAction
{
    use AsAction;

    /**
     * @param  array<string, mixed>  $form
     */
    public function handle(
        User $user,
        array $form,
        Carbon $startsAt,
        Carbon $endsAt,
        string $timezone,
        Institution|Person $primaryOrganizer,
        ?string $locationInstitutionId,
    ): Event {
        return DB::transaction(function () use ($user, $form, $startsAt, $endsAt, $timezone, $primaryOrganizer, $locationInstitutionId): Event {
            $personSlugSegments = $primaryOrganizer instanceof Person
                ? app(GenerateEventSlugAction::class)->personSlugSegmentsForPersonIds([(string) $primaryOrganizer->getKey()])
                : [];

            $event = Event::query()->create([
                'owner_type' => $user->getMorphClass(),
                'owner_id' => $user->id,
                'title' => (string) $form['title'],
                'slug' => app(GenerateEventSlugAction::class)->handle(
                    (string) $form['title'],
                    $startsAt,
                    $timezone,
                    null,
                    $personSlugSegments,
                ),
                'description' => (string) ($form['description'] ?? ''),
                'timezone' => $timezone,
                'institution_id' => $locationInstitutionId,
                'delivery_mode' => (string) $form['default_event_format'],
                'visibility' => (string) $form['visibility'],
                'registration_mode' => empty($form['registration_required'])
                    ? RegistrationMode::None->value
                    : RegistrationMode::Required->value,
                'status' => 'draft',
            ]);

            app(SyncEventClassificationsAction::class)->handle($event, [
                'event_category_ids' => is_array($form['default_event_category_ids'] ?? null)
                    ? $form['default_event_category_ids']
                    : [(string) ($form['default_event_category_id'] ?? '')],
            ]);

            app(SyncEventScheduleAction::class)->execute(
                event: $event,
                scheduleKind: ScheduleKind::Single,
                startsAt: $startsAt,
                endsAt: $endsAt,
                timezone: $timezone,
                timingMode: TimingMode::Absolute,
            );

            $event->accessPolicy()->create([
                'registration_required' => (bool) $form['registration_required'],
                'walk_in_allowed' => ! (bool) $form['registration_required'],
            ]);

            $event->setPrimaryOrganizer($primaryOrganizer);

            return $event;
        });
    }
}
