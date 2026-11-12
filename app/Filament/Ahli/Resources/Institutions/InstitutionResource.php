<?php

namespace App\Filament\Ahli\Resources\Institutions;

use App\Filament\Ahli\Resources\Institutions\Pages\EditInstitution;
use App\Filament\Resources\Institutions\RelationManagers\DonationChannelsRelationManager;
use App\Filament\Resources\Institutions\RelationManagers\MemberInvitationsRelationManager;
use App\Filament\Resources\Institutions\Schemas\InstitutionForm;
use App\Models\Institution;
use App\Platform\Auditing\AuditsRelationManager;
use App\Shared\Auth\AccountActor;
use App\Shared\Membership\MembershipDirectory;
use App\Shared\Membership\MembershipSubjectType;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InstitutionResource extends Resource
{
    #[\Override]
    protected static ?string $model = Institution::class;

    #[\Override]
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingLibrary;

    #[\Override]
    protected static ?string $recordTitleAttribute = 'name';

    #[\Override]
    protected static ?string $navigationLabel = 'Institutions';

    #[\Override]
    protected static ?string $navigationParentItem = 'Events';

    #[\Override]
    protected static ?int $navigationSort = 20;

    #[\Override]
    protected static ?string $slug = 'institutions';

    #[\Override]
    public static function form(Schema $schema): Schema
    {
        return InstitutionForm::configure($schema);
    }

    /**
     * @return Builder<Institution>
     */
    #[\Override]
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<Institution> $query */
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (! $user instanceof AccountActor) {
            return $query->whereRaw('1 = 0');
        }

        $subjectIds = app(MembershipDirectory::class)->subjectIdsFor(
            $user->actorId(),
            MembershipSubjectType::Institution,
        );

        return $query->whereIn('institutions.id', $subjectIds);
    }

    #[\Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[\Override]
    public static function getRelations(): array
    {
        return [
            'donation_channels' => DonationChannelsRelationManager::class,
            'member_invitations' => MemberInvitationsRelationManager::class,
            AuditsRelationManager::class,
        ];
    }

    #[\Override]
    public static function getPages(): array
    {
        return [
            'edit' => EditInstitution::route('/{record}/edit'),
        ];
    }

    /**
     * @param  array<mixed>  $parameters
     */
    #[\Override]
    public static function getIndexUrl(
        array $parameters = [],
        bool $isAbsolute = true,
        ?string $panel = null,
        ?Model $tenant = null,
        bool $shouldGuessMissingParameters = false
    ): string {
        return Filament::getPanel($panel ?? 'ahli')->getUrl($tenant) ?? route('dashboard.institutions');
    }
}
