<?php

namespace App\Filament\Resources\Institutions\Schemas;

use AIArmada\Contacting\Enums\ContactMethodType;
use AIArmada\Contacting\Enums\ContactPurpose;
use AIArmada\Contacting\Enums\SocialPlatform;
use AIArmada\Contacting\Support\SocialProfileConfig;
use App\Enums\InstitutionNameType;
use App\Enums\InstitutionType;
use App\Forms\SharedFormSchema;
use App\Models\Institution;
use App\Models\User;
use App\Support\Submission\PublicSubmissionLockService;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class InstitutionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Profile')
                    ->components([
                        Select::make('type')
                            ->options(InstitutionType::class)
                            ->required(),
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255),
                        Repeater::make('names')
                            ->relationship()
                            ->schema([
                                Select::make('name_type')
                                    ->options(InstitutionNameType::class)
                                    ->required()
                                    ->live(),
                                TextInput::make('full_name')
                                    ->required()
                                    ->maxLength(255),
                                TextInput::make('language_code')
                                    ->maxLength(10)
                                    ->default('ms'),
                                Toggle::make('is_primary')
                                    ->default(false),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Add alternative name'),
                        TextInput::make('slug')
                            ->required(fn (string $operation): bool => $operation !== 'create')
                            ->hidden(fn (string $operation): bool => $operation === 'create')
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        RichEditor::make('description')
                            ->columnSpanFull()
                            ->maxLength(5000),
                    ])
                    ->columns(2),
                Section::make('Media')
                    ->components([
                        SpatieMediaLibraryFileUpload::make('logo')
                            ->collection('logo')
                            ->image()
                            ->imageEditor()
                            ->avatar()
                            ->conversion('thumb')
                            ->helperText('Institution logo (recommended: 400x400)'),
                        SpatieMediaLibraryFileUpload::make('cover')
                            ->collection('cover')
                            ->label('Cover Image')
                            ->image()
                            ->imageEditor()
                            ->imageAspectRatio('16:9')
                            ->automaticallyOpenImageEditorForAspectRatio()
                            ->imageEditorAspectRatioOptions(['16:9'])
                            ->automaticallyCropImagesToAspectRatio()
                            ->responsiveImages()
                            ->conversion('banner')
                            ->helperText('Main image (recommended: 1200x675)'),
                        SpatieMediaLibraryFileUpload::make('gallery')
                            ->collection('gallery')
                            ->multiple()
                            ->reorderable()
                            ->image()
                            ->conversion('gallery_thumb')
                            ->responsiveImages()
                            ->columnSpanFull()
                            ->helperText('Additional images for gallery'),
                    ])
                    ->columns(2),
                Section::make('Contact')
                    ->components([
                        Repeater::make('contactMethods')
                            ->relationship()
                            ->schema([
                                Select::make('type')
                                    ->options(ContactMethodType::options())
                                    ->required()
                                    ->live(),
                                ...SharedFormSchema::contactValueFields(),
                                Select::make('purpose')
                                    ->options(ContactPurpose::options())
                                    ->default(ContactPurpose::General->value)
                                    ->required(),
                                Toggle::make('is_public')
                                    ->label('Public')
                                    ->default(true),
                            ])
                            ->columns(4)
                            ->orderColumn('sort_order')
                            ->mutateRelationshipDataBeforeFillUsing(fn (array $data): array => SharedFormSchema::normalizeContactRowsForFill($data))
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => SharedFormSchema::normalizeContactRowsForSave($data))
                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => SharedFormSchema::normalizeContactRowsForSave($data))
                            ->itemLabel(fn (array $state): string => SharedFormSchema::contactItemLabel($state)),
                    ]),
                Section::make('Location')
                    ->statePath('address')
                    ->components(SharedFormSchema::addressFields(
                        includeCountryField: true,
                        showCountryField: false,
                        requireCountryField: true,
                    ))
                    ->columns(2),
                Section::make('Status')
                    ->components([
                        Select::make('status')
                            ->options([
                                'unverified' => 'Unverified',
                                'pending' => 'Pending',
                                'verified' => 'Verified',
                                'rejected' => 'Rejected',
                                'inactive' => 'Inactive',
                            ])
                            ->required(),
                        Toggle::make('allow_public_event_submission')
                            ->label('Allow Public Event Submission')
                            ->disabled(fn (?Institution $record, string $operation): bool => ! self::canManagePublicSubmissionToggle($record, $operation))
                            ->helperText(fn (?Institution $record, string $operation): string => self::publicSubmissionHelperText($record, $operation)),
                    ])
                    ->columns(1),
                Section::make('Social Media')
                    ->components([
                        Repeater::make('socialProfiles')
                            ->relationship()
                            ->schema([
                                Select::make('platform')
                                    ->options(SocialPlatform::options())
                                    ->searchable()
                                    ->required()
                                    ->live()
                                    ->columnSpan(1),
                                TextInput::make('handle')
                                    ->label('Username / Handle')
                                    ->required()
                                    ->placeholder('username or https://...')
                                    ->live()
                                    ->afterStateUpdated(function (Get $get, Set $set, ?string $state): void {
                                        if ($state === null || $state === '' || ! str_contains($state, '://')) {
                                            return;
                                        }
                                        $platform = $get('platform');
                                        if ($platform === null || $platform === '') {
                                            return;
                                        }
                                        $platformValue = $platform instanceof SocialPlatform ? $platform->value : $platform;
                                        $extracted = app(SocialProfileConfig::class)->extractHandle($platformValue, $state);
                                        if ($extracted !== null) {
                                            $set('handle', $extracted);
                                        }
                                    })
                                    ->columnSpan(1)
                                    ->visible(function (Get $get): bool {
                                        $platform = $get('platform');
                                        if ($platform === null || $platform === '') {
                                            return false;
                                        }
                                        $value = $platform instanceof SocialPlatform ? $platform->value : $platform;

                                        return $value !== SocialPlatform::Website->value && $value !== SocialPlatform::Other->value;
                                    }),
                                Placeholder::make('profile_url')
                                    ->label('Profile URL')
                                    ->content(function (Get $get): ?string {
                                        $platform = $get('platform');
                                        $handle = $get('handle');
                                        if (! is_string($handle) || $handle === '') {
                                            return null;
                                        }
                                        $value = $platform instanceof SocialPlatform ? $platform->value : $platform;

                                        return app(SocialProfileConfig::class)->buildUrl($value, $handle);
                                    })
                                    ->columnSpanFull()
                                    ->visible(function (Get $get): bool {
                                        $platform = $get('platform');
                                        if ($platform === null || $platform === '') {
                                            return false;
                                        }
                                        $value = $platform instanceof SocialPlatform ? $platform->value : $platform;

                                        return $value !== SocialPlatform::Website->value && $value !== SocialPlatform::Other->value;
                                    }),
                                TextInput::make('url')
                                    ->label('URL')
                                    ->required()
                                    ->url()
                                    ->maxLength(255)
                                    ->columnSpanFull()
                                    ->visible(function (Get $get): bool {
                                        $platform = $get('platform');
                                        if ($platform === null || $platform === '') {
                                            return false;
                                        }
                                        $value = $platform instanceof SocialPlatform ? $platform->value : $platform;

                                        return $value === SocialPlatform::Website->value || $value === SocialPlatform::Other->value;
                                    }),
                            ])
                            ->columns(2)
                            ->orderColumn('sort_order')
                            ->itemLabel(function (array $state): ?string {
                                $platform = $state['platform'] ?? null;

                                if ($platform instanceof SocialPlatform) {
                                    return $platform->label();
                                }

                                if (is_string($platform)) {
                                    return SocialPlatform::tryFrom($platform)?->label() ?? $platform;
                                }

                                return null;
                            }),
                    ]),
            ]);
    }

    private static function canManagePublicSubmissionToggle(?Institution $record, string $operation): bool
    {
        if ($operation !== 'edit' || ! $record instanceof Institution) {
            return false;
        }

        if (! self::hasPublicSubmissionToggleAccess()) {
            return false;
        }

        if (! $record->allow_public_event_submission) {
            return true;
        }

        return app(PublicSubmissionLockService::class)->institutionEligibility($record)->eligible;
    }

    private static function hasPublicSubmissionToggleAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->hasAnyRole(['super_admin', 'admin', 'moderator']);
    }

    private static function publicSubmissionHelperText(?Institution $record, string $operation): string
    {
        if ($operation !== 'edit') {
            return 'Public event submission defaults to enabled on create.';
        }

        if (! $record instanceof Institution) {
            return 'Use this toggle to control whether the public can submit events for this institution.';
        }

        if (! self::hasPublicSubmissionToggleAccess()) {
            return 'Only global admins can change this setting.';
        }

        if (! $record->allow_public_event_submission) {
            return 'Enabled means anyone can submit. Disabled means only institution members can submit.';
        }

        $eligibility = app(PublicSubmissionLockService::class)->institutionEligibility($record);

        if ($eligibility->eligible) {
            return 'Turn this off to shift submission responsibility entirely to institution members.';
        }

        return 'Cannot turn this off yet: '.implode(' ', $eligibility->reasons);
    }
}
