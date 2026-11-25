<?php

namespace App\Features\Contributions\Actions;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Contributions\Enums\ContributionRequestStatus;
use App\Features\Contributions\Enums\ContributionRequestType;
use App\Features\Contributions\Exceptions\ContributionRequestNotPendingException;
use App\Features\Contributions\Support\ContributionModel;
use App\Features\Institutions\Api\InstitutionWorkflow;
use App\Features\Persons\Api\PersonWorkflow;
use App\Features\References\Api\ReferenceWorkflow;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class CancelContributionRequestAction
{
    use AsAction;

    public function __construct(
        private readonly InstitutionWorkflow $institutionWorkflow,
        private readonly PersonWorkflow $personWorkflow,
        private readonly ReferenceWorkflow $referenceWorkflow,
    ) {}

    /**
     * @param  \App\Features\Contributions\Models\ContributionRequest  $request
     * @return \App\Features\Contributions\Models\ContributionRequest
     */
    public function handle(Model $request, Model $proposer): Model
    {
        OwnerContext::withOwner(null, function () use ($request, $proposer): void {
            if ((string) $request->proposer_id !== (string) $proposer->getKey()) {
                throw new RuntimeException('Only the original proposer can cancel this request.');
            }

            $now = now();

            DB::transaction(function () use ($request, $now): void {
                ContributionModel::query('contribution_request')->whereKey($request->getKey())->lockForUpdate()->firstOrFail();
                $request->refresh();

                if (! $request->isPending()) {
                    throw new ContributionRequestNotPendingException('Only pending contribution requests can be cancelled.');
                }

                $request->forceFill([
                    'status' => ContributionRequestStatus::Cancelled,
                    'cancelled_at' => $now,
                    'last_state_change_at' => $now,
                ])->save();

                if ($request->type === ContributionRequestType::Create) {
                    $this->deactivateStagedEntity($request->entity);
                }
            });
        });

        // Staged uploads never took effect, so drop them with the request.
        // Media file deletion is immediate and cannot be rolled back, so it
        // runs after the row transition commits.
        $request->clearMediaCollection('pending_media');

        return $request->fresh(['entity', 'proposer', 'reviewer']) ?? $request;
    }

    /**
     * Deactivate a staged create entity so the contributor can re-submit it
     * cleanly. Verified or rejected entities are left untouched.
     */
    private function deactivateStagedEntity(?Model $entity): void
    {
        if (! $entity instanceof Model) {
            return;
        }

        $status = $entity->getAttribute('status');
        $statusValue = $status instanceof BackedEnum ? $status->value : (string) $status;

        if ($statusValue !== 'pending') {
            return;
        }

        if (ContributionModel::isInstitution($entity)) {
            $this->institutionWorkflow->changeStatus((string) $entity->getKey(), 'inactive');

            return;
        }

        if (ContributionModel::isPerson($entity)) {
            $this->personWorkflow->changeStatus((string) $entity->getKey(), 'inactive');

            return;
        }

        if (ContributionModel::isReference($entity)) {
            $this->referenceWorkflow->changeStatus((string) $entity->getKey(), 'inactive');
        }
    }
}
