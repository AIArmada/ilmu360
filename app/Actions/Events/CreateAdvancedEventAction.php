<?php

namespace App\Actions\Events;

use AIArmada\Events\Actions\CreateEventOccurrenceAction;
use AIArmada\Events\Enums\RegistrationMode;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Speaker;
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
        Institution|Speaker $primaryOrganizer,
        ?string $locationInstitutionId,
    ): Event {
        return DB::transaction(function () use ($user, $form, $startsAt, $endsAt, $timezone, $primaryOrganizer, $locationInstitutionId): Event {
            $speakerSlugSegments = $primaryOrganizer instanceof Speaker
                ? app(GenerateEventSlugAction::class)->speakerSlugSegmentsForSpeakerIds([(string) $primaryOrganizer->getKey()])
                : [];

            $event = Event::query()->create([
                'user_id' => $user->id,
                'submitter_id' => $user->id,
                'title' => (string) $form['title'],
                'slug' => app(GenerateEventSlugAction::class)->handle(
                    (string) $form['title'],
                    $startsAt,
                    $timezone,
                    null,
                    $speakerSlugSegments,
                ),
                'description' => (string) ($form['description'] ?? ''),
                'timezone' => $timezone,
                'institution_id' => $locationInstitutionId,
                'event_format' => (string) $form['default_event_format'],
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

            app(CreateEventOccurrenceAction::class)->handle($event, [
                'title' => $event->title,
                'slug' => $event->slug,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'timezone' => $timezone,
                'visibility' => $event->visibility,
                'delivery_mode' => $event->delivery_mode,
            ]);

            $event->accessPolicy()->create([
                'registration_required' => (bool) $form['registration_required'],
                'walk_in_allowed' => ! (bool) $form['registration_required'],
            ]);

            $event->setPrimaryOrganizer($primaryOrganizer);

            return $event;
        });
    }
}
