<?php

declare(strict_types=1);

namespace App\Features\Identity\EntryPoints\Filament\Resources\Authz;

use AIArmada\FilamentAuthz\Resources\UserResource as BaseUserResource;
use AIArmada\FilamentAuthz\Support\UserAuthzForm;
use AIArmada\FilamentAuthz\Tables\Actions\ImpersonateTableAction;
use App\Features\Identity\EntryPoints\Filament\Resources\Authz\UserResource\Pages\CreateUser;
use App\Features\Identity\EntryPoints\Filament\Resources\Authz\UserResource\Pages\EditUser;
use App\Features\Identity\EntryPoints\Filament\Resources\Authz\UserResource\Pages\ListUsers;
use App\Features\Identity\EntryPoints\Filament\Resources\Authz\UserResource\Pages\ViewUser;
use App\Models\User;
use Filament\Actions;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Ysfkaya\FilamentPhoneInput\Forms\PhoneInput;
use Ysfkaya\FilamentPhoneInput\PhoneInputNumberType;
use Ysfkaya\FilamentPhoneInput\Tables\PhoneColumn;

class UserResource extends BaseUserResource
{
    #[\Override]
    protected static function hasAuthzDirectoryAccess(): bool
    {
        if (auth()->user()?->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        return parent::hasAuthzDirectoryAccess();
    }

    #[\Override]
    protected static function checkAbility(string $action): bool
    {
        if (auth()->user()?->hasAnyRole(['super_admin', 'admin'])) {
            return true;
        }

        return parent::checkAbility($action);
    }

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->schema([
            Section::make('User Details')
                ->schema(static::getConfiguredFormFields())
                ->columns(2),
            ...UserAuthzForm::components(),
            Section::make('Protected Scoped Roles')
                ->description('Change protected institution, person, event, and reference ownership roles from this central admin surface.')
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
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['roles', 'globalRoles']))
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
     * @return list<Field|PhoneInput>
     */
    protected static function getConfiguredFormFields(): array
    {
        return array_map(
            static fn (Field $component): Field|PhoneInput => match ($component->getName()) {
                'phone' => PhoneInput::make('phone')->initialCountry('MY'),
                'timezone' => $component->helperText('Leave empty to use the application default timezone.'),
                'email_verified_at' => $component instanceof DateTimePicker
                    ? $component->label('Email Verified At')->native(false)->seconds(false)
                    : $component->label('Email Verified At'),
                'phone_verified_at' => $component instanceof DateTimePicker
                    ? $component->label('Phone Verified At')->native(false)->seconds(false)
                    : $component->label('Phone Verified At'),
                default => $component,
            },
            parent::getDefaultFormFields(),
        );
    }
}
