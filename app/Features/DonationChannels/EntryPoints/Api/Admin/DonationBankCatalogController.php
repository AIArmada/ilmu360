<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\EntryPoints\Api\Admin;

use App\Features\DonationChannels\Api\DonationChannelCountryProfiles;
use App\Platform\Models\MorphModelResolver;
use Dedoc\Scramble\Attributes\Endpoint;
use Dedoc\Scramble\Attributes\Group;
use Dedoc\Scramble\Attributes\QueryParameter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

#[Group('Admin Catalog', 'Authenticated catalog endpoints for schema-driven admin writes.')]
final class DonationBankCatalogController extends Controller
{
    #[QueryParameter('owner_type', 'Donation channel owner morph type (institution, person, event, organization).', required: false, type: 'string', infer: false)]
    #[QueryParameter('owner_id', 'Donation channel owner key used to resolve the owner country.', required: false, type: 'string', infer: false)]
    #[QueryParameter('country_code', 'ISO 3166-1 alpha-2 code when no owner is given.', required: false, type: 'string', infer: false)]
    #[Endpoint(
        title: 'List admin donation banks catalog',
        description: 'Returns the trusted bank catalog for the resolved owner country. An empty list means the country has no bank catalog coverage, so bank-account donations are unavailable there.',
    )]
    public function __invoke(Request $request, DonationChannelCountryProfiles $profiles): JsonResponse
    {
        $ownerType = trim($request->string('owner_type')->toString());
        $ownerId = trim($request->string('owner_id')->toString());

        $profile = $ownerType !== '' && $ownerId !== ''
            ? $profiles->forOwner($this->owner($ownerType, $ownerId))
            : $profiles->forCountryCode($request->string('country_code')->toString());

        return response()->json([
            'data' => collect($profile->banks->options())
                ->map(static fn (string $name, int|string $code): array => ['code' => (string) $code, 'name' => $name])
                ->values()
                ->all(),
            'meta' => [
                'country_code' => $profile->country_code,
                'covered' => $profile->banks->covers(),
                'supports_routing_codes' => $profile->supports_routing_codes,
            ],
        ]);
    }

    private function owner(string $ownerType, string $ownerId): Model
    {
        $modelClass = MorphModelResolver::tryResolve($ownerType);

        if ($modelClass === null && class_exists($ownerType) && is_a($ownerType, Model::class, true)) {
            $modelClass = $ownerType;
        }

        if ($modelClass === null) {
            throw ValidationException::withMessages([
                'owner_type' => __('The selected donation channel owner type is invalid.'),
            ]);
        }

        $owner = $modelClass::query()->find($ownerId);

        if (! $owner instanceof Model) {
            throw ValidationException::withMessages([
                'owner_id' => __('The selected donation channel owner was not found.'),
            ]);
        }

        return $owner;
    }
}
