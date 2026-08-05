<?php

declare(strict_types=1);

namespace App\Organizations;

use AIArmada\Organizations\Contracts\CurrentOrganizationResolver as CurrentOrganizationResolverContract;
use AIArmada\Organizations\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final readonly class CurrentOrganizationResolver implements CurrentOrganizationResolverContract
{
    public function __construct(private Request $request) {}

    public function resolve(): ?Organization
    {
        $actor = Auth::user();

        if (! $actor instanceof Model) {
            return null;
        }

        $organizationKey = $this->request->header('X-Organization-Id')
            ?: $this->request->query('organization_id')
            ?: $this->request->route('organization');

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
