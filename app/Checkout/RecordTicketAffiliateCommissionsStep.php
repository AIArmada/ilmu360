<?php

namespace App\Checkout;

use AIArmada\Checkout\Data\StepResult;
use AIArmada\Checkout\Models\CheckoutSession;
use AIArmada\Checkout\Steps\AbstractCheckoutStep;
use App\Actions\Events\RecordTicketAffiliateCommissionsAction;
use App\Actions\Events\ReverseTicketAffiliateCommissionAction;
use App\Models\Registration;
use Illuminate\Support\Str;
use Throwable;

final class RecordTicketAffiliateCommissionsStep extends AbstractCheckoutStep
{
    public function __construct(
        private readonly RecordTicketAffiliateCommissionsAction $recordCommissions,
        private readonly ReverseTicketAffiliateCommissionAction $reverseCommissions,
    ) {}

    public function getIdentifier(): string
    {
        return 'record_ticket_affiliate_commissions';
    }

    public function getName(): string
    {
        return 'Record Ticket Affiliate Commissions';
    }

    /** @return array<string> */
    public function getDependencies(): array
    {
        return ['record_checkout_share_provenance'];
    }

    public function handle(CheckoutSession $session): StepResult
    {
        $registrationIds = $this->stringList(
            $session->getStepData('create_event_registrations')['registration_ids'] ?? [],
        );

        if ($registrationIds === []) {
            return $this->skipped('No event registrations were created.');
        }

        $recordedIds = $this->recordCommissions->handle($session, $registrationIds);

        if ($recordedIds === []) {
            return $this->skipped('No eligible commercial ticket commissions were recorded.');
        }

        $session->setStepData($this->getIdentifier(), ['registration_ids' => $recordedIds]);

        return $this->success(
            sprintf('%d ticket affiliate commissions recorded.', count($recordedIds)),
            ['registration_ids' => $recordedIds],
        );
    }

    public function compensate(CheckoutSession $session): StepResult
    {
        $registrationIds = $this->stringList(
            $session->getStepData($this->getIdentifier())['registration_ids'] ?? [],
        );
        $errors = [];
        $reversed = 0;

        foreach ($registrationIds as $registrationId) {
            $registration = Registration::query()->whereKey($registrationId)->first();

            if (! $registration instanceof Registration) {
                continue;
            }

            try {
                if ($this->reverseCommissions->handle($registration, 'checkout_compensated') !== null) {
                    $reversed++;
                }
            } catch (Throwable $exception) {
                $errors[$registrationId] = $exception->getMessage() !== ''
                    ? $exception->getMessage()
                    : $exception::class;
            }
        }

        if ($errors !== []) {
            return $this->failed('Ticket affiliate commission compensation failed.', $errors);
        }

        return $this->compensated(
            'Ticket affiliate commissions compensated.',
            ['registration_ids' => $registrationIds, 'reversed' => $reversed],
        );
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $ids = [];

        foreach ($value as $candidate) {
            if (is_string($candidate) && Str::isUuid($candidate)) {
                $ids[] = $candidate;
            }
        }

        return array_values(array_unique($ids));
    }
}
