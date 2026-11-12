<?php

declare(strict_types=1);

namespace App\Features\Events\Actions\Submission;

use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\Events\Enums\RegistrationMode;
use AIArmada\Events\Models\Event as PackageEvent;
use AIArmada\Events\Models\EventSubmission as PackageEventSubmission;
use App\Features\Events\Support\EventModel;
use RuntimeException;

/**
 * Persist the completion writes owned by Events.
 *
 * Guest submitter contacts are attached to the submission and a newly created
 * parent event is normalised to open-door admission. Session submissions and
 * grouped child submissions write no event state. Moderation, share tracking
 * and notifications remain with the root completion orchestrator.
 */
final readonly class CompleteEventSubmissionWritesAction
{
    /**
     * @param  array<string, mixed>  $state
     * @return 'approve'|'submit'|null
     */
    public function handle(
        string $eventId,
        string $submissionId,
        array $state,
        bool $storeSubmitterContacts,
        bool $sessionSubmission,
        bool $parentCreated,
        bool $autoApproved,
    ): ?string {
        if ($storeSubmitterContacts) {
            $this->storeSubmitterContacts($this->submission($submissionId), $state);
        }

        // Session submissions attach to an existing container; grouped child
        // submissions keep the parent's admission defaults and lifecycle
        // state untouched.
        if ($sessionSubmission || ! $parentCreated) {
            return null;
        }

        $event = EventModel::query('event')->withoutOwnerScope()->find($eventId);

        if (! $event instanceof PackageEvent) {
            throw new RuntimeException("The event [{$eventId}] does not exist.");
        }

        $event->forceFill(['registration_mode' => RegistrationMode::None->value])->save();
        $event->accessPolicy()->updateOrCreate(
            ['event_id' => $event->getKey()],
            ['registration_required' => false, 'walk_in_allowed' => true],
        );

        if ($autoApproved) {
            return 'approve';
        }

        return (string) $event->status === 'draft' ? 'submit' : null;
    }

    /** @return \App\Models\EventSubmission */
    private function submission(string $submissionId): PackageEventSubmission
    {
        $submission = EventModel::query('event_submission')->find($submissionId);

        if (! $submission instanceof PackageEventSubmission) {
            throw new RuntimeException("The event submission [{$submissionId}] does not exist.");
        }

        return $submission;
    }

    /**
     * @param  \App\Models\EventSubmission  $submission
     * @param  array<string, mixed>  $state
     */
    private function storeSubmitterContacts(PackageEventSubmission $submission, array $state): void
    {
        $order = 1;

        foreach (['submitter_email' => ContactMethodType::Email, 'submitter_phone' => ContactMethodType::Phone] as $key => $type) {
            if (! filled($state[$key] ?? null)) {
                continue;
            }

            $submission->contactMethods()->create([
                'type' => $type->value,
                'purpose' => ContactPurpose::General->value,
                'value' => $state[$key],
                'is_public' => false,
                'sort_order' => $order++,
            ]);
        }
    }
}
