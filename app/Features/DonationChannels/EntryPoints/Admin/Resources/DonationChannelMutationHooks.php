<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\EntryPoints\Admin\Resources;

use App\Features\DonationChannels\Api\DonationChannelOptions;
use App\Features\DonationChannels\Api\DonationChannelWorkflow;
use App\Features\DonationChannels\Api\Enums\DonationChannelStatus;
use App\Features\DonationChannels\Models\DonationChannel;
use App\Models\User;
use App\Platform\Admin\Concerns\BuildsAdminFieldMetadata;
use App\Platform\Api\Admin\Contracts\AdminMutationHooks;
use App\Shared\Support\MorphAliasModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final readonly class DonationChannelMutationHooks implements AdminMutationHooks
{
    use BuildsAdminFieldMetadata;

    public function __construct(private DonationChannelWorkflow $donationChannels) {}

    public function supports(string $resourceClass): bool
    {
        return $resourceClass === \App\Features\DonationChannels\EntryPoints\Filament\Resources\DonationChannels\DonationChannelResource::class;
    }

    public function schema(string $resourceClass, string $resourceKey, string $operation, ?Model $record, array $defaults): ?array
    {
        if (! $this->supports($resourceClass)) {
            return null;
        }

        $updating = $operation === 'update';

        return [
            'resource_key' => $resourceKey,
            'operation' => $operation,
            'method' => $updating ? 'PUT' : 'POST',
            'endpoint' => $updating && $record instanceof Model
                ? route('api.admin.resources.update', ['resourceKey' => $resourceKey, 'recordKey' => $this->recordKey($record)], false)
                : route('api.admin.resources.store', ['resourceKey' => $resourceKey], false),
            'content_type' => 'multipart/form-data',
            'slug_behavior' => 'not_applicable',
            'defaults' => $defaults,
            'current_media' => $record instanceof DonationChannel ? $this->mediaState($record, ['qr']) : null,
            'fields' => $this->fields(),
            'catalogs' => [
                $this->catalog('bank_code', route('api.admin.catalogs.donation-banks', [], false), [
                    'owner_type' => '{donatable_type}',
                    'owner_id' => '{donatable_id}',
                ]),
            ],
            'conditional_rules' => [
                ['field' => 'bank_code', 'required_when' => ['method' => ['bank_account']]],
                ['field' => 'duitnow_type', 'required_when' => ['method' => ['duitnow']]],
                ['field' => 'duitnow_value', 'required_when' => ['method' => ['duitnow']]],
                ['field' => 'ewallet_provider', 'required_when' => ['method' => ['ewallet']]],
            ],
        ];
    }

    public function rules(string $resourceClass, bool $updating): ?array
    {
        if (! $this->supports($resourceClass)) {
            return null;
        }

        $required = 'required';
        $maxUploadSize = 'max:'.$this->maxUploadSizeKb();

        return [
            'donatable_type' => [$required, 'string', Rule::in($this->ownerTypeValues())],
            'donatable_id' => [$required, 'uuid'],
            'label' => ['nullable', 'string', 'max:255'],
            'recipient' => [$required, 'string', 'max:255'],
            'method' => [$required, Rule::in(['bank_account', 'duitnow', 'ewallet'])],
            'currency' => ['nullable', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/'],
            'bank_code' => ['nullable', 'string', 'max:32', 'required_if:method,bank_account'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'account_number' => ['nullable', 'string', 'max:64'],
            'iban' => ['nullable', 'string', 'max:34'],
            'swift_bic' => ['nullable', 'string', 'max:11'],
            'routing_code' => ['nullable', 'string', 'max:20'],
            'duitnow_type' => ['nullable', 'string', 'required_if:method,duitnow', Rule::in(array_keys(DonationChannelOptions::DUITNOW_ID_TYPES))],
            'duitnow_value' => ['nullable', 'string', 'max:255', 'required_if:method,duitnow'],
            'ewallet_provider' => ['nullable', 'string', 'required_if:method,ewallet', Rule::in(array_keys(DonationChannelOptions::EWALLET_PROVIDERS))],
            'ewallet_handle' => ['nullable', 'string', 'max:255'],
            'ewallet_qr_payload' => ['nullable', 'string'],
            'reference_note' => ['nullable', 'string'],
            'status' => [$required, Rule::enum(DonationChannelStatus::class)],
            'is_default' => ['sometimes', 'boolean'],
            'qr' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', $maxUploadSize],
            'clear_qr' => ['sometimes', 'boolean'],
        ];
    }

    public function normalize(string $resourceClass, array $validated, ?Model $record): array
    {
        if (! $this->supports($resourceClass)) {
            return $validated;
        }

        $ownerType = array_key_exists('donatable_type', $validated)
            ? $validated['donatable_type']
            : ($record instanceof DonationChannel ? (string) $record->donatable_type : null);

        $ownerId = array_key_exists('donatable_id', $validated)
            ? trim((string) $validated['donatable_id'])
            : ($record instanceof DonationChannel ? (string) $record->donatable_id : '');

        if (! is_string($ownerType) || ! in_array($ownerType, $this->ownerTypeValues(), true)) {
            throw ValidationException::withMessages([
                'donatable_type' => __('The selected donation channel owner type is invalid.'),
            ]);
        }

        if ($ownerId === '') {
            throw ValidationException::withMessages([
                'donatable_id' => __('The selected donation channel owner is invalid.'),
            ]);
        }

        $ownerModelClass = $this->ownerModelClass($ownerType);

        if (! $ownerModelClass::query()->whereKey($ownerId)->exists()) {
            throw ValidationException::withMessages([
                'donatable_id' => __('The selected donation channel owner is invalid.'),
            ]);
        }

        $validated['donatable_type'] = $ownerType;
        $validated['donatable_id'] = $ownerId;

        return $validated;
    }

    public function store(string $resourceClass, array $validated, User $actor): ?Model
    {
        return $this->supports($resourceClass) ? $this->save($validated, $actor) : null;
    }

    public function update(string $resourceClass, Model $record, array $validated, User $actor): ?Model
    {
        if (! $this->supports($resourceClass)) {
            return null;
        }

        if (! $record instanceof DonationChannel) {
            throw ValidationException::withMessages([
                'record' => __('The selected donation channel is invalid.'),
            ]);
        }

        return $this->save($validated, $actor, $record);
    }

    public function defaultsForCreate(string $resourceClass): ?array
    {
        if (! $this->supports($resourceClass)) {
            return null;
        }

        return [
            'donatable_type' => $this->ownerType('institution'),
            'donatable_id' => null,
            'label' => null,
            'recipient' => '',
            'method' => 'bank_account',
            'currency' => null,
            'bank_code' => null,
            'bank_name' => null,
            'account_number' => null,
            'iban' => null,
            'swift_bic' => null,
            'routing_code' => null,
            'duitnow_type' => null,
            'duitnow_value' => null,
            'ewallet_provider' => null,
            'ewallet_handle' => null,
            'ewallet_qr_payload' => null,
            'reference_note' => null,
            'status' => DonationChannelStatus::Pending->value,
            'is_default' => false,
            'clear_qr' => false,
        ];
    }

    public function augmentDefaultsForRecord(Model $record, array $defaults): array
    {
        if (! $record instanceof DonationChannel) {
            return $defaults;
        }

        return [
            'donatable_type' => (string) $record->donatable_type,
            'donatable_id' => (string) $record->donatable_id,
            'label' => $record->label,
            'recipient' => $record->recipient,
            'method' => (string) $record->method,
            'currency' => $record->currency,
            'bank_code' => $record->bank_code,
            'bank_name' => $record->bank_name,
            'account_number' => $record->account_number,
            'iban' => $record->iban,
            'swift_bic' => $record->swift_bic,
            'routing_code' => $record->routing_code,
            'duitnow_type' => $record->duitnow_type,
            'duitnow_value' => $record->duitnow_value,
            'ewallet_provider' => $record->ewallet_provider,
            'ewallet_handle' => $record->ewallet_handle,
            'ewallet_qr_payload' => $record->ewallet_qr_payload,
            'reference_note' => $record->reference_note,
            'status' => $record->status->value,
            'is_default' => (bool) $record->is_default,
            'clear_qr' => false,
        ];
    }

    /** @param array<string, mixed> $validated */
    private function save(array $validated, User $actor, ?DonationChannel $record = null): DonationChannel
    {
        Gate::forUser($actor)->authorize($record instanceof DonationChannel ? 'update' : 'create', $record ?? DonationChannel::class);

        $recordId = $record instanceof DonationChannel ? (string) $record->getKey() : null;
        $channelId = $this->donationChannels->save($validated, $recordId);

        return DonationChannel::query()->findOrFail($channelId);
    }

    /** @return list<string> */
    private function ownerTypeValues(): array
    {
        return [
            $this->ownerType('institution'),
            $this->ownerType('person'),
            $this->ownerType('event'),
            $this->ownerType('organization'),
        ];
    }

    private function ownerType(string $alias): string
    {
        $class = MorphAliasModel::classFor($alias);

        return (string) (new $class)->getMorphClass();
    }

    /** @return class-string<Model> */
    private function ownerModelClass(string $ownerType): string
    {
        foreach (['institution', 'person', 'event', 'organization'] as $alias) {
            if ($this->ownerType($alias) === $ownerType) {
                return MorphAliasModel::classFor($alias);
            }
        }

        throw new \RuntimeException('Unsupported donation channel owner type.');
    }

    /** @return list<array<string, mixed>> */
    private function fields(): array
    {
        return [
            $this->field('donatable_type', 'string', required: true, default: $this->ownerType('institution'), allowedValues: $this->ownerTypeValues(), meta: [
                'mutation_semantics' => 'replace_scalar_with_canonical_owner_type',
                'paired_with' => 'donatable_id',
            ]),
            $this->field('donatable_id', 'string', required: true, meta: [
                'paired_with' => 'donatable_type',
                'relation_lookup' => 'resolved_from_normalized_owner_type',
            ]),
            $this->field('label', 'string', required: false, maxLength: 255, meta: $this->trimmedStringMutationMeta()),
            $this->field('recipient', 'string', required: true, maxLength: 255),
            $this->field('method', 'string', required: true, default: 'bank_account', allowedValues: ['bank_account', 'duitnow', 'ewallet'], meta: [
                'mutation_semantics' => 'replace_scalar_with_method_partition_reset',
                'switch_clears_fields' => [
                    'bank_account' => ['duitnow_type', 'duitnow_value', 'ewallet_provider', 'ewallet_handle', 'ewallet_qr_payload'],
                    'duitnow' => ['bank_code', 'bank_name', 'account_number', 'iban', 'swift_bic', 'routing_code', 'ewallet_provider', 'ewallet_handle', 'ewallet_qr_payload'],
                    'ewallet' => ['bank_code', 'bank_name', 'account_number', 'iban', 'swift_bic', 'routing_code', 'duitnow_type', 'duitnow_value'],
                ],
            ]),
            $this->field('currency', 'string', required: false, maxLength: 3, meta: $this->trimmedStringMutationMeta()),
            $this->field('bank_code', 'string', required: false, maxLength: 32, meta: [
                ...$this->trimmedStringMutationMeta(),
                'canonical_bank_identifier' => true,
                'catalog' => 'bank_code',
            ]),
            $this->field('bank_name', 'string', required: false, maxLength: 255, meta: [
                'mutation_semantics' => 'derived_server_side',
                'derived_from' => 'bank_code',
                'accepted_when' => 'omitted_or_matches_the_catalog_name',
            ]),
            $this->field('account_number', 'string', required: false, maxLength: 64),
            $this->field('iban', 'string', required: false, maxLength: 34, meta: $this->trimmedStringMutationMeta()),
            $this->field('swift_bic', 'string', required: false, maxLength: 11, meta: $this->trimmedStringMutationMeta()),
            $this->field('routing_code', 'string', required: false, maxLength: 20, meta: [
                ...$this->trimmedStringMutationMeta(),
                'used_when' => 'bank_account',
                'supported_when' => 'owner_country_catalog_supports_routing_codes',
            ]),
            $this->field('duitnow_type', 'string', required: false, allowedValues: array_keys(DonationChannelOptions::DUITNOW_ID_TYPES)),
            $this->field('duitnow_value', 'string', required: false, maxLength: 255),
            $this->field('ewallet_provider', 'string', required: false, allowedValues: array_keys(DonationChannelOptions::EWALLET_PROVIDERS)),
            $this->field('ewallet_handle', 'string', required: false, maxLength: 255, meta: $this->trimmedStringMutationMeta()),
            $this->field('ewallet_qr_payload', 'string', required: false, meta: $this->trimmedStringMutationMeta()),
            $this->field('reference_note', 'string', required: false, meta: $this->trimmedStringMutationMeta()),
            $this->field('status', 'string', required: true, default: DonationChannelStatus::Pending->value, allowedValues: $this->enumValues(DonationChannelStatus::class)),
            $this->field('is_default', 'boolean', required: false, default: false),
            $this->field('qr', 'file', required: false, acceptedMimeTypes: ['image/jpeg', 'image/png', 'image/webp'], maxFileSizeKb: $this->maxUploadSizeKb()),
            $this->field('clear_qr', 'boolean', required: false, default: false),
        ];
    }
}
