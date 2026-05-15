<?php

namespace App\Filament\Resources\Institutions\Schemas;

use App\Enums\ContactCategory;
use App\Enums\ContactType;
use App\Enums\InstitutionType;
use App\Enums\SocialMediaPlatform;
use App\Forms\SharedFormSchema;
use App\Models\Institution;
use App\Models\User;
use App\Support\Location\FederalTerritoryLocation;
use App\Support\Submission\PublicSubmissionLockService;
use Filament\Forms\Components\Hidden;
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
                        TextInput::make('nickname')
                            ->maxLength(255)
                            ->helperText('Optional nickname, e.g. Masjid Biru'),
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
                        Repeater::make('contacts')
                            ->relationship()
                            ->schema([
                                Select::make('category')
                                    ->options(ContactCategory::class)
                                    ->required()
                                    ->live(),
                                ...SharedFormSchema::contactValueFields(),
                                Select::make('type')
                                    ->options(ContactType::class)
                                    ->default(ContactType::Main)
                                    ->required(),
                                Toggle::make('is_public')
                                    ->label('Public')
                                    ->default(true),
                            ])
                            ->columns(4)
                            ->orderColumn('order_column')
                            ->mutateRelationshipDataBeforeFillUsing(fn (array $data): array => SharedFormSchema::normalizeContactRowsForFill($data))
                            ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => SharedFormSchema::normalizeContactRowsForSave($data))
                            ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => SharedFormSchema::normalizeContactRowsForSave($data))
                            ->itemLabel(fn (array $state): string => SharedFormSchema::contactItemLabel($state)),
                    ]),
                Section::make('Location')
                    ->relationship('address')
                    ->mutateRelationshipDataBeforeFillUsing(fn (array $data): array => SharedFormSchema::hydrateAddressFormState($data))
                    ->mutateRelationshipDataBeforeCreateUsing(fn (array $data): array => SharedFormSchema::prepareAddressPersistenceData($data))
                    ->mutateRelationshipDataBeforeSaveUsing(fn (array $data): array => SharedFormSchema::prepareAddressPersistenceData($data))
                    ->components([
                        Select::make('country_id')
                            ->relationship('country', 'name')
                            ->default(132) // Malaysia
                            ->required()
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function (Set $set) {
                                $set('state_id', null);
                                $set('district_id', null);
                                $set('subdistrict_id', null);
                            }),
                        Select::make('state_id')
                            ->label('State')
                            ->relationship('state', 'name', fn ($query, $get) => $query->where('country_id', $get('country_id')))
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function (Set $set) {
                                $set('district_id', null);
                                $set('subdistrict_id', null);
                            }),
                        Select::make('district_id')
                            ->label('District')
                            ->relationship('district', 'name', fn ($query, $get) => $query->where('state_id', $get('state_id')))
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('subdistrict_id', null))
                            ->visible(fn (Get $get): bool => filled($get('state_id')) && ! FederalTerritoryLocation::isFederalTerritoryStateId($get('state_id'))),
                        Select::make('subdistrict_id')
                            ->label('Subdistrict / Mukim')
                            ->options(fn (Get $get): array => SharedFormSchema::subdistrictOptionsForSelection($get('state_id'), $get('district_id')))
                            ->searchable()
                            ->preload()
                            ->visible(fn (Get $get): bool => SharedFormSchema::shouldShowSubdistrictField($get('state_id'), $get('district_id'))),
                        TextInput::make('line1')
                            ->maxLength(255),
                        TextInput::make('line2')
                            ->maxLength(255),
                        TextInput::make('postcode')
                            ->maxLength(16),
                        Hidden::make('google_display_name'),
                        Hidden::make('google_resolution_source'),
                        Hidden::make('google_resolution_status'),
                        Hidden::make('google_resolution_fingerprint'),
                        Hidden::make('google_resolution_message'),
                        TextInput::make('lat')
                            ->numeric()
                            ->minValue(-90)
                            ->maxValue(90),
                        TextInput::make('lng')
                            ->numeric()
                            ->minValue(-180)
                            ->maxValue(180),
                        SharedFormSchema::googleMapsUrlField(defaultHelperText: 'Paste the full Google Maps link from your browser'),
                        TextInput::make('google_place_id')
                            ->label('Google Place ID')
                            ->maxLength(255)
                            ->helperText('Optional: For advanced integrations'),
                        TextInput::make('waze_url')
                            ->label('Waze URL')
                            ->url()
                            ->maxLength(255),
                    ])
                    ->columns(2),
                Section::make('Status')
                    ->components([
                        Select::make('status')
                            ->options([
                                'unverified' => 'Unverified',
                                'pending' => 'Pending',
                                'verified' => 'Verified',
                                'rejected' => 'Rejected',
                            ])
                            ->required(),
                        Toggle::make('allow_public_event_submission')
                            ->label('Allow Public Event Submission')
                            ->disabled(fn (?Institution $record, string $operation): bool => ! self::canManagePublicSubmissionToggle($record, $operation))
                            ->helperText(fn (?Institution $record, string $operation): string => self::publicSubmissionHelperText($record, $operation)),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ])
                    ->columns(1),
                Section::make('Social Media')
                    ->components([
                        Repeater::make('socialMedia')
                            ->relationship()
                            ->schema([
                                Select::make('platform')
                                    ->options(SocialMediaPlatform::class)
                                    ->searchable()
                                    ->required()
                                    ->columnSpan(1),
                                TextInput::make('username')
                                    ->label('Username / Handle')
                                    ->requiredWithout('url')
                                    ->placeholder('@username / https://...')
                                    ->columnSpan(1),
                                TextInput::make('url')
                                    ->label('URL')
                                    ->requiredWithout('username')
                                    ->url()
                                    ->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->orderColumn('order_column')
                            ->itemLabel(function (array $state): ?string {
                                $platform = $state['platform'] ?? null;

                                if ($platform instanceof SocialMediaPlatform) {
                                    return $platform->getLabel();
                                }

                                if (is_string($platform)) {
                                    return SocialMediaPlatform::tryFrom($platform)?->getLabel() ?? $platform;
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
