<?php

declare(strict_types=1);

namespace App\Features\Directory\Services;

use App\Features\Directory\Actions\Spaces\AttachInstitutionSpaceAction;
use App\Features\Directory\Actions\Spaces\DetachInstitutionSpaceAction;
use App\Features\Directory\Api\InstitutionSpaceLinks;

/**
 * Implements the institution↔space bridge boundary through named actions.
 */
final readonly class InstitutionSpaceLinkService implements InstitutionSpaceLinks
{
    public function __construct(
        private AttachInstitutionSpaceAction $attachLink,
        private DetachInstitutionSpaceAction $detachLink,
    ) {}

    public function attach(string $institutionId, string $spaceId, ?int $capacity = null): void
    {
        $this->attachLink->handle($institutionId, $spaceId, $capacity);
    }

    public function detach(string $institutionId, string $spaceId): void
    {
        $this->detachLink->handle($institutionId, $spaceId);
    }

    public function deleteForSpace(string $spaceId): void
    {
        $this->detachLink->forSpace($spaceId);
    }

    public function deleteForInstitution(string $institutionId): void
    {
        $this->detachLink->forInstitution($institutionId);
    }
}
