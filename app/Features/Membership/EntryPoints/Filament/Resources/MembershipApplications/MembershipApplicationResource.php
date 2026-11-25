<?php

namespace App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications;

use AIArmada\CommerceSupport\Support\VersionedCache;
use AIArmada\Membership\Enums\ApplicationStatus;
use App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\Pages\ListMembershipApplications;
use App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\Pages\ViewMembershipApplication;
use App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\Schemas\MembershipApplicationInfolist;
use App\Features\Membership\EntryPoints\Filament\Resources\MembershipApplications\Tables\MembershipApplicationsTable;
use App\Features\Membership\Models\MembershipApplication;
use App\Platform\Auditing\AuditsRelationManager;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class MembershipApplicationResource extends Resource
{
    private static ?string $navigationBadgeScope = null;

    private static ?int $pendingNavigationCount = null;

    #[\Override]
    protected static ?string $model = MembershipApplication::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    #[\Override]
    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        if (! $record instanceof Model) {
            return null;
        }

        return $record->applicant->name ?? (string) $record->getKey();
    }

    #[\Override]
    protected static string|UnitEnum|null $navigationGroup = 'Moderation';

    #[\Override]
    protected static ?int $navigationSort = 4;

    #[\Override]
    public static function infolist(Schema $schema): Schema
    {
        return MembershipApplicationInfolist::configure($schema);
    }

    #[\Override]
    public static function table(Table $table): Table
    {
        return MembershipApplicationsTable::configure($table);
    }

    /**
     * @return Builder<MembershipApplication>
     */
    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        // The admin panel has no tenancy; querying the model directly keeps
        // the owner-scope call statically typed. Revisit if panel tenancy is
        // enabled, because parent::getEloquentQuery() applies tenant scoping.
        $query = MembershipApplication::query()->withoutOwnerScope();

        return $query->with(['applicant', 'reviewer', 'media']);
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
            'index' => ListMembershipApplications::route('/'),
            'view' => ViewMembershipApplication::route('/{record}'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        $scope = app()->bound('request') ? spl_object_hash(request()) : 'console';

        if (self::$navigationBadgeScope !== $scope) {
            self::$navigationBadgeScope = $scope;
            self::$pendingNavigationCount = app(VersionedCache::class)->rememberInt(
                MembershipApplication::PENDING_COUNT_VERSION_KEY,
                'nav_badge:membership_applications:pending:count',
                30,
                static fn (): int => MembershipApplication::query()
                    ->withoutOwnerScope()
                    ->where('status', ApplicationStatus::Pending->value)
                    ->count(),
            );
        }

        $count = self::$pendingNavigationCount ?? 0;

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return self::getNavigationBadge() !== null ? 'warning' : null;
    }

    public static function flushNavigationBadgeCache(): void
    {
        self::$navigationBadgeScope = null;
        self::$pendingNavigationCount = null;
    }

    #[\Override]
    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyRole(['super_admin', 'admin', 'moderator']) ?? false;
    }
}
