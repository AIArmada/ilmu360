<?php

declare(strict_types=1);

namespace App\Platform\Api\Frontend;

use App\Models\User;
use App\Platform\Api\Frontend\Contracts\FrontendFormContractContributor;
use InvalidArgumentException;
use LogicException;

/**
 * Registry of frontend form-contract contributors.
 *
 * Contributors are registered as class strings and resolved when a contract
 * is requested, so config-sensitive owner dependencies are built at request
 * time rather than during provider boot.
 */
final class FrontendFormContractRegistry
{
    /** @var list<class-string<FrontendFormContractContributor>> */
    private array $contributors = [];

    /**
     * @param  class-string<FrontendFormContractContributor>  $contributor
     */
    public function register(string $contributor): void
    {
        if (! in_array($contributor, $this->contributors, true)) {
            $this->contributors[] = $contributor;
        }
    }

    public function supports(string $key): bool
    {
        return array_any($this->contributors(), fn ($contributor) => $contributor->supports($key));
    }

    /**
     * @return array<string, mixed>
     */
    public function manifest(?User $user): array
    {
        $manifest = [];

        foreach ($this->contributors() as $contributor) {
            $fragment = $contributor->manifest($user);

            if ($fragment === null) {
                continue;
            }

            $manifest = array_replace_recursive($manifest, $fragment);
        }

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function contract(string $key, array $context = []): array
    {
        foreach ($this->contributors() as $contributor) {
            if ($contributor->supports($key)) {
                return $contributor->contract($key, $context);
            }
        }

        throw new InvalidArgumentException(sprintf(
            'No frontend form contract contributor supports [%s].',
            $key,
        ));
    }

    /**
     * @return list<FrontendFormContractContributor>
     */
    private function contributors(): array
    {
        $resolved = [];

        foreach ($this->contributors as $contributorClass) {
            $contributor = app($contributorClass);

            if (! $contributor instanceof FrontendFormContractContributor) {
                throw new LogicException(sprintf(
                    'Frontend form contract contributor [%s] must implement [%s].',
                    $contributorClass,
                    FrontendFormContractContributor::class,
                ));
            }

            $resolved[] = $contributor;
        }

        return $resolved;
    }
}
