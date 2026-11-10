<?php

declare(strict_types=1);

namespace App\Features\Directory\Api;

/**
 * Submission eligibility for one institution.
 */
final readonly class InstitutionSubmissionEligibility
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public bool $eligible,
        public array $reasons = [],
    ) {}

    public static function eligible(): self
    {
        return new self(true, []);
    }

    /**
     * @param  list<string>  $reasons
     */
    public static function ineligible(array $reasons): self
    {
        return new self(false, $reasons);
    }
}
