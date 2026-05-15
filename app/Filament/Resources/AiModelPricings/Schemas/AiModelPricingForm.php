<?php

declare(strict_types=1);

namespace App\Filament\Resources\AiModelPricings\Schemas;

use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AiModelPricingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Match Rules')
                    ->components([
                        TextInput::make('provider')
                            ->default('*')
                            ->required()
                            ->maxLength(120)
                            ->helperText('Use exact provider key (e.g. openai) or * for global fallback.'),

                        TextInput::make('model_pattern')
                            ->default('*')
                            ->required()
                            ->maxLength(255)
                            ->helperText('Supports wildcard pattern matching (e.g. gpt-4.1* or */*:free).'),

                        Select::make('operation')
                            ->default('*')
                            ->required()
                            ->native(false)
                            ->options([
                                '*' => 'All Operations',
                                'agent_prompt' => 'Agent Prompt',
                                'agent_stream' => 'Agent Stream',
                                'image_generation' => 'Image Generation',
                                'transcription_generation' => 'Transcription',
                                'embeddings_generation' => 'Embeddings',
                                'reranking' => 'Reranking',
                                'audio_generation' => 'Audio Generation',
                            ]),

                        TextInput::make('tier')
                            ->maxLength(120)
                            ->helperText('Optional tier (e.g. free, pro). Leave empty for default tier.'),

                        TextInput::make('currency')
                            ->default('USD')
                            ->required()
                            ->length(3),

                        TextInput::make('priority')
                            ->label('Priority (lower is stronger)')
                            ->numeric()
                            ->default(100)
                            ->minValue(1)
                            ->required(),

                        Toggle::make('is_active')
                            ->default(true)
                            ->required(),

                        DateTimePicker::make('starts_at')
                            ->seconds(false),

                        DateTimePicker::make('ends_at')
                            ->seconds(false),
                    ])->columns(3),

                Section::make('Token Rates (USD per 1,000,000 tokens)')
                    ->components([
                        TextInput::make('input_per_million')
                            ->numeric()
                            ->step('0.00000001'),

                        TextInput::make('output_per_million')
                            ->numeric()
                            ->step('0.00000001'),

                        TextInput::make('cache_write_input_per_million')
                            ->numeric()
                            ->step('0.00000001'),

                        TextInput::make('cache_read_input_per_million')
                            ->numeric()
                            ->step('0.00000001'),

                        TextInput::make('reasoning_per_million')
                            ->numeric()
                            ->step('0.00000001'),
                    ])->columns(3),

                Section::make('Non-Token Rates')
                    ->components([
                        TextInput::make('per_request')
                            ->numeric()
                            ->step('0.00000001')
                            ->helperText('Flat charge per invocation.'),

                        TextInput::make('per_image')
                            ->numeric()
                            ->step('0.00000001')
                            ->helperText('Used for image generation when set.'),

                        TextInput::make('per_audio_second')
                            ->numeric()
                            ->step('0.00000001')
                            ->helperText('Used when duration in seconds is available.'),

                        Textarea::make('notes')
                            ->rows(3)
                            ->columnSpanFull(),
                    ])->columns(3),
            ]);
    }
}
