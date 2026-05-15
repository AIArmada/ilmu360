<?php

declare(strict_types=1);

namespace App\Filament\Resources\Authz;

use AIArmada\FilamentAuthz\Resources\UserResource as BaseUserResource;
use AIArmada\FilamentAuthz\Support\UserAuthzForm;
use AIArmada\FilamentAuthz\Tables\Actions\ImpersonateTableAction;
use App\Filament\RelationManagers\AuditsRelationManager;
use App\Filament\Resources\Authz\UserResource\Pages\CreateUser;
use App\Filament\Resources\Authz\UserResource\Pages\EditUser;
use App\Filament\Resources\Authz\UserResource\Pages\ListUsers;
use App\Filament\Resources\Authz\UserResource\Pages\ViewUser;
use App\Models\User;
use DateTimeZone;
use Filament\Actions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;
use Ysfkaya\FilamentPhoneInput\Forms\PhoneInput;
use Ysfkaya\FilamentPhoneInput\PhoneInputNumberType;
use Ysfkaya\FilamentPhoneInput\Tables\PhoneColumn;

class UserResource extends BaseUserResource
{
    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('User Details')
                ->schema(static::getConfiguredFormFields())
                ->columns(2),
            ...UserAuthzForm::components(),
            Section::make('Protected Scoped Roles')
                ->description('Change protected institution, speaker, event, and reference ownership roles from this central admin surface.')
                ->schema([
                    View::make('filament.resources.authz.user-resource.components.protected-scoped-role-manager'),
                ])
                ->columnSpanFull()
                ->visible(fn (?User $record): bool => $record instanceof User),
            Section::make('Memberships')
                ->schema([
                    View::make('filament.resources.authz.user-resource.components.membership-summary'),
                ])
                ->columnSpanFull()
                ->visible(fn (?User $record): bool => $record instanceof User),
        ]);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return parent::table($table)
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('email')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                PhoneColumn::make('phone')
                    ->displayFormat(PhoneInputNumberType::INTERNATIONAL)
                    ->searchable()
                    ->copyable()
                    ->placeholder('-'),
                TextColumn::make('globalRoles.name')
                    ->label('Global Roles')
                    ->badge()
                    ->color('primary')
                    ->searchable(),
                TextColumn::make('created_at')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('updated_at')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                ImpersonateTableAction::make(),
                Actions\ViewAction::make()
                    ->url(fn (Model $record): string => static::getUrl('view', ['record' => $record])),
                Actions\EditAction::make(),
            ]);
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            AuditsRelationManager::class,
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'view' => ViewUser::route('/{record}'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    /**
     * @return list<DateTimePicker|PhoneInput|Select|TextInput>
     */
    protected static function getConfiguredFormFields(): array
    {
        $fields = (array) config('filament-authz.user_resource.form.fields', ['name', 'email', 'password']);

        /** @var list<DateTimePicker|TextInput> $components */
        $components = [];

        foreach ($fields as $field) {
            $components[] = match ($field) {
                'name' => TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                'email' => TextInput::make('email')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                'phone' => PhoneInput::make('phone')
                    ->initialCountry('MY'),
                'timezone' => Select::make('timezone')
                    ->label('Timezone')
                    ->options(static::getTimezoneOptions())
                    ->searchable()
                    ->native(false)
                    ->helperText('Leave empty to use the application default timezone.'),
                'email_verified_at' => DateTimePicker::make('email_verified_at')
                    ->label('Email Verified At')
                    ->native(false)
                    ->seconds(false),
                'phone_verified_at' => DateTimePicker::make('phone_verified_at')
                    ->label('Phone Verified At')
                    ->native(false)
                    ->seconds(false),
                'password' => TextInput::make('password')
                    ->password()
                    ->dehydrateStateUsing(function (?string $state): ?string {
                        if ($state === null || $state === '') {
                            return null;
                        }

                        return Hash::make($state);
                    })
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->maxLength(255),
                default => TextInput::make((string) $field)
                    ->maxLength(255),
            };
        }

        return $components;
    }

    /**
     * @return array<string, string>
     */
    protected static function getTimezoneOptions(): array
    {
        return collect(DateTimeZone::listIdentifiers())
            ->mapWithKeys(fn (string $timezone): array => [$timezone => $timezone])
            ->all();
    }
}
