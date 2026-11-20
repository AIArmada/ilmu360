<?php

declare(strict_types=1);

namespace App\Features\Institutions\Queries;

use AIArmada\Addressing\Support\ModelResolver;
use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\Contacting\Enums\SocialPlatform;
use App\Features\DonationChannels\Models\DonationChannel;
use App\Features\Institutions\Api\ContributionProjection;
use App\Features\Institutions\Api\Enums\InstitutionNameType;
use App\Features\Institutions\Api\Enums\InstitutionType;
use App\Features\Institutions\Models\Institution;
use App\Features\Institutions\Models\InstitutionName;
use App\Platform\Models\MorphModelResolver;
use App\Platform\Validation\Rules\ValidAreaAssignmentRoles;
use BackedEnum;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use RuntimeException;

final readonly class InstitutionContributionProjection implements ContributionProjection
{
    use \App\Shared\Contributions\Support\BuildsContributionDescriptors;
    use \App\Shared\Contributions\Support\BuildsContributionSubjectState;

    /** @return array<string, mixed> */
    public function contractFor(string $subjectId): array
    {
        $this->resolve($subjectId);

        return [
            'accepts_partial_updates' => true,
            'fields' => [
                $this->field('name', 'string', maxLength: 255),
                $this->field('names', 'array'),
                $this->field('type', 'string', allowedValues: $this->enumValues(InstitutionType::class)),
                $this->field('description', 'rich_text'),
                $this->field('address', 'object'),
                $this->field('address.country_id', 'uuid', catalog: route('api.client.catalogs.countries')),
                $this->field('contactMethods', 'array<object>'),
                $this->field('social_media', 'array<object>'),
                $this->field('donation_channels', 'array<object>'),
            ],
            'conditional_rules' => [],
            'direct_edit_media_fields' => ['logo', 'cover', 'gallery'],
        ];
    }

    /** @return array<string, mixed> */
    public function stateFor(string $subjectId): array
    {
        return $this->institutionState($this->resolve($subjectId));
    }

    /** @return array<string, mixed> */
    public function updateRulesFor(string $subjectId): array
    {
        $this->resolve($subjectId);

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'names' => ['nullable', 'array'],
            'type' => ['sometimes', Rule::in($this->enumValues(InstitutionType::class))],
            'description' => ['nullable'],
            'address' => ['sometimes', 'array'],
            'address.country_id' => ['required_with:address', 'uuid', Rule::exists(ModelResolver::countryClass(), 'id')],
            'address.state_id' => ['nullable', 'uuid', Rule::exists(ModelResolver::stateClass(), 'id')],
            'address.city_id' => ['nullable', 'uuid', Rule::exists(ModelResolver::cityClass(), 'id')],
            'address.area_assignments' => ['sometimes', 'array', new ValidAreaAssignmentRoles],
            'address.area_assignments.*' => ['nullable', 'uuid', Rule::exists(ModelResolver::areaClass(), 'id')],
            'address.line1' => ['nullable', 'string', 'max:255'],
            'address.line2' => ['nullable', 'string', 'max:255'],
            'address.postcode' => ['nullable', 'string', 'max:16'],
            'address.latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'address.longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'address.google_maps_url' => ['nullable', 'url', 'max:255'],
            'address.google_place_id' => ['nullable', 'string', 'max:255'],
            'address.google_feature_id' => ['nullable', 'string', 'max:255'],
            'address.google_cid' => ['nullable', 'string', 'max:255'],
            'address.google_entity_id' => ['nullable', 'string', 'max:255'],
            'address.waze_url' => ['nullable', 'url', 'max:255'],
            'contactMethods' => ['sometimes', 'array'],
            'contactMethods.*.type' => ['required_with:contactMethods.*.value', Rule::in($this->enumValues(ContactMethodType::class))],
            'contactMethods.*.value' => ['required_with:contactMethods.*.type', 'string', 'max:255'],
            'contactMethods.*.purpose' => ['nullable', Rule::in($this->enumValues(ContactPurpose::class))],
            'contactMethods.*.is_public' => ['nullable', 'boolean'],
            'social_media' => ['sometimes', 'array'],
            'social_media.*.platform' => ['required_with:social_media.*.handle,social_media.*.url', Rule::in($this->enumValues(SocialPlatform::class))],
            'social_media.*.handle' => ['nullable', 'string', 'max:255', 'required_without:social_media.*.url'],
            'social_media.*.url' => ['nullable', 'url', 'max:255', 'required_without:social_media.*.handle'],
            'donation_channels' => ['sometimes', 'array'],
            'donation_channels.*.label' => ['nullable', 'string', 'max:255'],
            'donation_channels.*.recipient' => ['required_with:donation_channels.*.method', 'string', 'max:255'],
            'donation_channels.*.method' => ['required_with:donation_channels.*.recipient', Rule::in(['bank_account', 'duitnow', 'ewallet'])],
            'donation_channels.*.bank_name' => ['nullable', 'string', 'max:255'],
            'donation_channels.*.bank_code' => ['nullable', 'string', 'max:255'],
            'donation_channels.*.account_number' => ['nullable', 'string', 'max:2000'],
            'donation_channels.*.duitnow_type' => ['nullable', 'string', 'max:255'],
            'donation_channels.*.duitnow_value' => ['nullable', 'string', 'max:2000'],
            'donation_channels.*.ewallet_provider' => ['nullable', 'string', 'max:255'],
            'donation_channels.*.ewallet_handle' => ['nullable', 'string', 'max:255'],
            'donation_channels.*.ewallet_qr_payload' => ['nullable', 'string', 'max:2000'],
            'donation_channels.*.reference_note' => ['nullable', 'string', 'max:255'],
            'donation_channels.*.is_default' => ['nullable', 'boolean'],
        ];
    }

    private function resolve(string $subjectId): Institution
    {
        $modelClass = MorphModelResolver::resolve('institution');
        $model = $modelClass::query()->findOrFail($subjectId);

        if (! $model instanceof Institution) {
            throw new RuntimeException('Expected the resolved contribution subject to be a institution.');
        }

        return $model;
    }

    /** @return array<string, mixed> */
    private function institutionState(Institution $institution): array
    {
        $institution->loadMissing(['addresses', 'contactMethods', 'socialProfiles', 'names', 'donationChannels']);

        return [
            'name' => $institution->name,
            'names' => $institution->names->map(fn (InstitutionName $n): array => [
                'full_name' => $n->full_name,
                'name_type' => $n->name_type instanceof InstitutionNameType ? $n->name_type->value : (string) $n->name_type,
                'language_code' => $n->language_code,
                'is_primary' => (bool) $n->is_primary,
            ])->values()->all(),
            'type' => $institution->type instanceof BackedEnum ? $institution->type->value : (string) $institution->type,
            'description' => $institution->description,
            'address' => $this->addressState($institution->primaryAddress()),
            'contactMethods' => $this->contactMethodsState($institution->contactMethods),
            'social_media' => $this->socialMediaState($institution->socialProfiles),
            'donation_channels' => $this->donationChannelsState($institution->donationChannels),
        ];
    }

    /**
     * @param  Collection<int, DonationChannel>  $channels
     * @return list<array<string, mixed>>
     */
    private function donationChannelsState(Collection $channels): array
    {
        return $channels
            ->sortBy('created_at')
            ->map(fn (DonationChannel $channel): array => $this->donationChannelRow($channel))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function donationChannelRow(DonationChannel $channel): array
    {
        return [
            'label' => $channel->label,
            'recipient' => $channel->recipient,
            'method' => $channel->method,
            'bank_code' => $channel->bank_code,
            'bank_name' => $channel->bank_name,
            'account_number' => $channel->account_number,
            'duitnow_type' => $channel->duitnow_type,
            'duitnow_value' => $channel->duitnow_value,
            'ewallet_provider' => $channel->ewallet_provider,
            'ewallet_handle' => $channel->ewallet_handle,
            'ewallet_qr_payload' => $channel->ewallet_qr_payload,
            'reference_note' => $channel->reference_note,
            'is_default' => (bool) $channel->is_default,
        ];
    }
}
