<?php

declare(strict_types=1);

namespace App\Filament\Resources\Persons\RelationManagers;

use AIArmada\CommerceSupport\Filament\Concerns\VerifiesRelationManagerOwnerContext;
use AIArmada\CommerceSupport\Models\Language;
use App\Features\Directory\Api\PersonWorkflow;
use App\Models\Person;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Person names relation manager routed through the Directory workflow.
 */
final class NamesRelationManager extends RelationManager
{
    use VerifiesRelationManagerOwnerContext;

    protected static string $relationship = 'names';

    protected static ?string $title = 'Names';

    protected static ?string $recordTitleAttribute = 'full_name';

    /**
     * @return array<string, string>
     */
    public static function getLanguageOptions(): array
    {
        /** @var array<string, string> $options */
        $options = Cache::remember('filament-persons.languages', 3600, static function (): array {
            /** @var array<string, string> $languages */
            $languages = Language::query()->orderBy('name')->pluck('name', 'code')->all();

            return $languages;
        });

        return $options;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Select::make('name_type')
                    ->options([
                        'legal' => 'Legal',
                        'display' => 'Display',
                        'birth' => 'Birth',
                        'religious' => 'Religious',
                        'professional' => 'Professional',
                        'previous' => 'Previous',
                    ])
                    ->required(),
                TextInput::make('full_name')
                    ->required()
                    ->maxLength(255),
                Select::make('language_code')
                    ->options(fn (): array => self::getLanguageOptions())
                    ->searchable()
                    ->preload()
                    ->required()
                    ->default('en'),
                Checkbox::make('is_primary'),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name_type')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('full_name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('language_code')
                    ->badge(),
                Tables\Columns\IconColumn::make('is_primary')
                    ->boolean(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->action(function (CreateAction $action, array $data): void {
                        $owner = $this->getOwnerRecord();

                        if (! $owner instanceof Person) {
                            return;
                        }

                        app(PersonWorkflow::class)->saveName((string) $owner->getKey(), null, $data);
                        $action->success();
                    }),
            ])
            ->actions([
                EditAction::make()
                    ->action(function (EditAction $action, Model $record, array $data): void {
                        $owner = $this->getOwnerRecord();

                        if (! $owner instanceof Person) {
                            return;
                        }

                        app(PersonWorkflow::class)->saveName(
                            (string) $owner->getKey(),
                            (string) $record->getKey(),
                            $data,
                        );
                        $action->success();
                    }),
                DeleteAction::make()
                    ->action(function (DeleteAction $action, Model $record): void {
                        $owner = $this->getOwnerRecord();

                        if (! $owner instanceof Person) {
                            return;
                        }

                        app(PersonWorkflow::class)->deleteNames(
                            (string) $owner->getKey(),
                            [(string) $record->getKey()],
                        );
                        $action->success();
                    }),
            ]);
    }
}
