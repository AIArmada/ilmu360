<?php

declare(strict_types=1);

namespace App\Actions\Events;

use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\Events\Enums\RegistrationMode;
use App\Enums\DawahShareOutcomeType;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\User;
use App\Services\ModerationService;
use App\Services\ShareTrackingService;
use App\States\EventStatus\Pending;
use Illuminate\Http\Request;

final readonly class CompleteFrontendEventSubmissionAction
{
    public function __construct(
        private ShareTrackingService $shareTracking,
        private ModerationService $moderation,
    ) {}

    /**
     * @param  array<string, mixed>  $state
     */
    public function handle(
        Event $event,
        EventSubmission $submission,
        array $state,
        Request $request,
        ?User $submitter,
        bool $sessionSubmission,
        bool $autoApproved,
    ): void {
        $this->shareTracking->recordOutcome(
            type: DawahShareOutcomeType::EventSubmission,
            outcomeKey: 'event_submission:submission:'.$submission->getKey(),
            subject: $event,
            actor: $submitter,
            request: $request,
            metadata: [
                'submission_id' => $submission->getKey(),
                'submitted_by' => $submission->submitter_id,
            ],
        );

        if (! $submitter instanceof User) {
            $this->storeSubmitterContacts($submission, $state);
        }

        if (! $sessionSubmission) {
            $event->forceFill(['registration_mode' => RegistrationMode::None->value])->save();
            $event->accessPolicy()->updateOrCreate(
                ['event_id' => $event->getKey()],
                ['registration_required' => false, 'walk_in_allowed' => true],
            );
        }

        if ($autoApproved) {
            $this->moderation->approve($event, null, 'Auto-approved from institution dashboard submission.');
        } elseif ((string) $event->status === 'draft') {
            $event->status->transitionTo(Pending::class);
        }
    }

    /**
     * @param  array<string, mixed>  $state
     */
    private function storeSubmitterContacts(EventSubmission $submission, array $state): void
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
