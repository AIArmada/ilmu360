<?php

declare(strict_types=1);

namespace App\Platform\ApiDocumentation;

use App\Platform\ApiDocumentation\Contracts\RequestExampleContributor;

final class RequestExampleRegistry
{
    /** @var list<RequestExampleContributor> */
    private array $contributors = [];

    public function register(RequestExampleContributor $contributor): void
    {
        if (! in_array($contributor, $this->contributors, true)) {
            $this->contributors[] = $contributor;
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function examples(): array
    {
        $examples = [];

        foreach ($this->contributors as $contributor) {
            $examples = [...$examples, ...$contributor->examples()];
        }

        return $examples;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $route): ?array
    {
        $example = $this->examples()[$route] ?? null;

        return is_array($example) ? $example : null;
    }
}
