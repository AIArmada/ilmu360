<?php

declare(strict_types=1);

namespace App\Features\Events\Api\Frontend;

use App\Features\Events\Api\AdvancedBuilderContext;
use App\Features\Events\Api\Enums\EventFormat;
use App\Features\Events\Api\Enums\EventVisibility;
use App\Features\Events\Api\EventCategoryCatalog;
use App\Features\Events\Api\RegistrationScope;
use App\Models\User;
use App\Platform\Api\Frontend\Contracts\FrontendFormContractContributor;
use App\Platform\Api\Frontend\FrontendFormContractKey;
use App\Platform\Api\Frontend\Support\FrontendFormContractField;
use InvalidArgumentException;

final readonly class AdvancedEventFormContractContributor implements FrontendFormContractContributor
{
    public function __construct(
        private AdvancedBuilderContext $builderContext,
        private EventCategoryCatalog $eventCategories,
    ) {}

    public function supports(string $key): bool
    {
        return $key === FrontendFormContractKey::ADVANCED_EVENT;
    }

    public function manifest(?User $user): ?array
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function contract(string $key, array $context): array
    {
        $user = $context['user'] ?? null;

        if (! $user instanceof User) {
            throw new InvalidArgumentException('The advanced event form contract requires an authenticated account.');
        }

        $builderContext = $this->builderContext->forAccount((string) $user->getKey());

        return [
            'flow' => 'advanced_event',
            'method' => 'POST',
            'endpoint' => route('api.client.advanced-events.store'),
            'auth_required' => true,
            'defaults' => $builderContext['default_form'],
            'fields' => [
                FrontendFormContractField::make('title', 'string', required: true, maxLength: 255),
                FrontendFormContractField::make('description', 'string', required: false),
                FrontendFormContractField::make('timezone', 'timezone', required: true, default: 'Asia/Kuala_Lumpur'),
                FrontendFormContractField::make('program_starts_at', 'datetime', required: true),
                FrontendFormContractField::make('program_ends_at', 'datetime', required: true),
                FrontendFormContractField::make('primary_organizer_id', 'uuid', required: true),
                FrontendFormContractField::make('location_institution_id', 'uuid', required: false),
                FrontendFormContractField::make('default_event_category_ids', 'array<uuid>', required: true, allowedValues: array_keys($this->eventCategories->options())),
                FrontendFormContractField::make('default_event_format', 'string', required: true, allowedValues: FrontendFormContractField::enumValues(EventFormat::class)),
                FrontendFormContractField::make('visibility', 'string', required: true, allowedValues: FrontendFormContractField::enumValues(EventVisibility::class)),
                FrontendFormContractField::make('registration_required', 'boolean', required: true),
                FrontendFormContractField::make('registration_mode', 'string', required: true, allowedValues: FrontendFormContractField::enumValues(RegistrationScope::class)),
            ],
            'options' => [
                'primary_organizer_options' => [
                    'institution' => $builderContext['institution_options'],
                    'person' => $builderContext['person_options'],
                ],
                'location_institution_options' => $builderContext['institution_options'],
            ],
        ];
    }
}
