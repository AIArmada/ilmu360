<?php

declare(strict_types=1);

namespace App\Features\Organizations\Services;

use AIArmada\Organizations\Contracts\CurrentOrganizationResolver as CurrentOrganizationResolverContract;
use AIArmada\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Resolves the organization selected by the current request.
 *
 * This resolver is deliberately stateless: the package registers it as a
 * singleton, so it must read the request and authenticated actor on every
 * call. Capturing a Request in the constructor would retain the first request
 * for the lifetime of an Octane worker.
 */
final readonly class CurrentOrganizationResolver implements CurrentOrganizationResolverContract
{
    public function resolve(): ?Organization
    {
        $actor = Auth::user();

        if (! $actor instanceof Model) {
            return null;
        }

        $request = request();
        $organizationKey = $request->header('X-Organization-Id')
            ?: $request->query('organization_id')
            ?: $request->route('organization');

        if ($organizationKey instanceof Organization) {
            $organizationKey = $organizationKey->getKey();
        }

        if (! is_string($organizationKey) || trim($organizationKey) === '') {
            return null;
        }

        return Organization::query()
            ->whereKey($organizationKey)
            ->whereHas('members', fn (Builder $query): Builder => $query->whereKey($actor->getKey()))
            ->first();
    }
}
