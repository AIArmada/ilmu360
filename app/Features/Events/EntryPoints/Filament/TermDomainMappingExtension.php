<?php

declare(strict_types=1);

namespace App\Features\Events\EntryPoints\Filament;

use AIArmada\Events\Models\EventTaxonomy;
use AIArmada\Events\Models\EventTerm;
use AIArmada\FilamentEvents\Contracts\TermFormExtension;
use App\Features\Events\Actions\Authoring\SyncEventTermDomainMappingAction;
use App\Features\Events\Api\EventTaxonomyCode;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

final class TermDomainMappingExtension implements TermFormExtension
{
    /**
     * @return array<int, Component>
     */
    public function components(): array
    {
        return [
            Section::make('Domain Mapping')
                ->description('Choose which Topik / Bidang show this term. Leave empty to show it under every topic.')
                ->visible(fn (Get $get): bool => $this->supportsDomainMapping($get('event_taxonomy_id')))
                ->schema([
                    Select::make('domain_ids')
                        ->label('Topik / Bidang')
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->options(fn (): array => $this->domainTermOptions())
                        ->afterStateHydrated(function (Select $component, ?EventTerm $record): void {
                            $component->state($record?->metadata['domain_ids'] ?? []);
                        })
                        ->dehydrated(false)
                        ->saveRelationshipsUsing(function (EventTerm $record, mixed $state): void {
                            app(SyncEventTermDomainMappingAction::class)->handle($record, $state);
                        }),
                ]),
        ];
    }

    private function supportsDomainMapping(mixed $taxonomyId): bool
    {
        if (! is_string($taxonomyId) || $taxonomyId === '') {
            return false;
        }

        $code = EventTaxonomy::query()->whereKey($taxonomyId)->value('code');

        return in_array($code, [EventTaxonomyCode::Discipline->value, EventTaxonomyCode::Issue->value], true);
    }

    /**
     * @return array<string, string>
     */
    private function domainTermOptions(): array
    {
        $taxonomyId = EventTaxonomy::query()->where('code', EventTaxonomyCode::Domain->value)->value('id');

        if (! is_string($taxonomyId)) {
            return [];
        }

        return EventTerm::query()
            ->where('event_taxonomy_id', $taxonomyId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
