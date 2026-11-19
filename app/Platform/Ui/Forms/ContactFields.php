<?php

namespace App\Platform\Ui\Forms;

use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use App\Features\Contributions\Api\ContributionPayload;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Ysfkaya\FilamentPhoneInput\Forms\PhoneInput;
use Ysfkaya\FilamentPhoneInput\PhoneInputNumberType;

class ContactFields
{
    public static function contactsRepeater(?string $helperText = null): Repeater
    {
        $repeater = Repeater::make('contactMethods')
            ->label(__('Contact Details'))
            ->default([])
            ->schema([
                Select::make('type')
                    ->label(__('Type'))
                    ->options(FormOptions::translatedOptions(ContactMethodType::options()))
                    ->required()
                    ->live(),
                ...self::contactValueFields(),
                Select::make('purpose')
                    ->label(__('Purpose'))
                    ->options(FormOptions::translatedOptions(ContactPurpose::options()))
                    ->default(ContactPurpose::General->value)
                    ->required(),
                Grid::make(2)->schema([
                    Toggle::make('is_primary')
                        ->label(__('Primary'))
                        ->fixIndistinctState(),
                    Toggle::make('is_public')
                        ->label(__('Public'))
                        ->default(true),
                ])->columnSpanFull(),
            ])
            ->columns(4)
            ->addActionLabel(__('Add contact details'))
            ->mutateRelationshipDataBeforeFillUsing(fn (array $data): array => self::normalizeContactRowsForFill($data))
            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => self::normalizeContactRowsForSave($data))
            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => self::normalizeContactRowsForSave($data));

        if ($helperText !== null) {
            $repeater->helperText(__($helperText));
        }

        return $repeater;
    }

    /**
     * @return array<int, TextInput|PhoneInput>
     */
    public static function contactValueFields(): array
    {
        $phoneTypes = [ContactMethodType::Phone, ContactMethodType::Phone->value, ContactMethodType::Whatsapp, ContactMethodType::Whatsapp->value, ContactMethodType::Mobile, ContactMethodType::Mobile->value];
        $emailTypes = [ContactMethodType::Email, ContactMethodType::Email->value];

        return [
            PhoneInput::make('phone_value')
                ->label(fn (Get $get): string => match ($get('type')) {
                    ContactMethodType::Phone, ContactMethodType::Phone->value => __('Phone Number'),
                    ContactMethodType::Whatsapp, ContactMethodType::Whatsapp->value => __('WhatsApp Number'),
                    ContactMethodType::Mobile, ContactMethodType::Mobile->value => __('Mobile Number'),
                    default => __('Value'),
                })
                ->required()
                ->visible(fn (Get $get): bool => in_array($get('type'), $phoneTypes, true))
                ->dehydrated(fn (Get $get): bool => in_array($get('type'), $phoneTypes, true))
                ->afterStateHydrated(function (PhoneInput $component, mixed $state, Get $get, Set $set): void {
                    if (! self::isPhoneContactType($get('type'))) {
                        return;
                    }

                    $value = self::normalizedContactValue($state) ?? self::normalizedContactValue($get('value'));

                    if ($value === null) {
                        return;
                    }

                    $component->state($value);
                    $set('value', $value);
                })
                ->afterStateUpdated(function (Set $set, mixed $state): void {
                    $set('value', self::normalizedContactValue($state));
                })
                ->initialCountry('MY')
                ->displayNumberFormat(PhoneInputNumberType::INTERNATIONAL)
                ->inputNumberFormat(PhoneInputNumberType::E164),
            TextInput::make('value')
                ->label(fn (Get $get): string => match ($get('type')) {
                    ContactMethodType::Email, ContactMethodType::Email->value => __('Email Address'),
                    default => __('Value'),
                })
                ->required()
                ->maxLength(255)
                ->visible(fn (Get $get): bool => ! in_array($get('type'), $phoneTypes, true))
                ->dehydrated(fn (Get $get): bool => ! in_array($get('type'), $phoneTypes, true))
                ->email(fn (Get $get): bool => in_array($get('type'), $emailTypes, true)),
        ];
    }

    /**
     * @param  array<string, mixed>|list<array<string, mixed>>  $data
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public static function normalizeContactRowsForFill(array $data): array
    {
        if (array_is_list($data)) {
            return array_map(self::normalizeContactRowForFill(...), $data);
        }

        return self::normalizeContactRowForFill($data);
    }

    /**
     * @param  array<string, mixed>|list<array<string, mixed>>  $data
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public static function normalizeContactRowsForSave(array $data): array
    {
        if (array_is_list($data)) {
            return array_map(self::normalizeContactRowForSave(...), $data);
        }

        return self::normalizeContactRowForSave($data);
    }

    /**
     * @param  array<string, mixed>|list<array<string, mixed>>  $data
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public static function normalizeContactRowsForComparison(array $data): array
    {
        return ContributionPayload::normalizeContactRowsForComparison($data);
    }

    /**
     * @param  array<string, mixed>  $contact
     * @return array<string, mixed>
     */
    private static function normalizeContactRowForFill(array $contact): array
    {
        $contact['type'] ??= $contact['category'] ?? null;
        $contact['purpose'] ??= ContactPurpose::General->value;

        unset($contact['category']);

        if (self::isPhoneContactType($contact['type'] ?? null)) {
            $contact['phone_value'] = $contact['value'] ?? null;
        }

        return $contact;
    }

    /**
     * @param  array<string, mixed>  $contact
     * @return array<string, mixed>
     */
    private static function normalizeContactRowForSave(array $contact): array
    {
        $contact['type'] ??= $contact['category'] ?? null;
        $contact['purpose'] ??= ContactPurpose::General->value;
        $type = $contact['type'] ?? null;

        unset($contact['category']);

        if (self::isPhoneContactType($type)) {
            $value = self::normalizedContactValue($contact['phone_value'] ?? $contact['value'] ?? null);

            if ($value !== null) {
                $contact['value'] = $value;
            }

            unset($contact['phone_value']);

            return $contact;
        }

        $value = self::normalizedContactValue($contact['value'] ?? null);

        if ($value !== null) {
            $contact['value'] = $value;
        }

        unset($contact['phone_value']);

        return $contact;
    }

    public static function isPhoneContactType(mixed $type): bool
    {
        return ContributionPayload::isPhoneContactType($type);
    }

    public static function normalizedContactValue(mixed $value): ?string
    {
        return ContributionPayload::normalizedContactValue($value);
    }

    public static function normalizedComparablePhoneValue(mixed $value): ?string
    {
        return ContributionPayload::normalizedComparablePhoneValue($value);
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function contactItemLabel(array $state): string
    {
        $type = $state['type'] ?? $state['category'] ?? null;

        if ($type instanceof ContactMethodType) {
            $typeLabel = $type->label();
        } elseif (is_string($type)) {
            $typeLabel = ContactMethodType::tryFrom($type)?->label() ?? $type;
        } else {
            $typeLabel = __('Contact');
        }

        $value = self::isPhoneContactType($type)
            ? self::normalizedContactValue($state['phone_value'] ?? ($state['value'] ?? null))
            : self::normalizedContactValue($state['value'] ?? null);

        return $typeLabel.': '.($value ?? '');
    }
}
