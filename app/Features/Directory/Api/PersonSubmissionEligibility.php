<?php

declare(strict_types=1);

namespace App\Features\Directory\Api;

/**
 * Person public-submission eligibility snapshot.
 *
 * Values only: callers receive the decision and reasons without a model.
 */
final readonly class PersonSubmissionEligibility
{
    /**
     * @param  list<string>  $reasons
     */
    public function __construct(
        public bool $eligible,
        public array $reasons,
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
        return new self(false, array_values($reasons));
    }
}
