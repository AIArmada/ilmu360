<?php

declare(strict_types=1);

use Laravel\Ai\Provider;

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider Names
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the AI providers below should be the
    | default for AI operations when no explicit provider is provided
    | for the operation. This should be any provider defined below.
    |
    */

    'default' => 'openai',
    'default_for_images' => 'gemini',
    'default_for_audio' => 'openai',
    'default_for_transcription' => 'openai',
    'default_for_embeddings' => 'openai',
    'default_for_reranking' => 'cohere',

    /*
    |--------------------------------------------------------------------------
    | Caching
    |--------------------------------------------------------------------------
    |
    | Below you may configure caching strategies for AI related operations
    | such as embedding generation. You are free to adjust these values
    | based on your application's available caching stores and needs.
    |
    */

    'caching' => [
        'embeddings' => [
            'cache' => false,
            'store' => env('CACHE_STORE', 'database'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | Below are each of your AI providers defined for this application. Each
    | represents an AI provider and API key combination which can be used
    | to perform tasks like text, image, and audio creation via agents.
    |
    */

    'providers' => [
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
        ],

        'cohere' => [
            'driver' => 'cohere',
            'key' => env('COHERE_API_KEY'),
        ],

        'deepseek' => [
            'driver' => 'deepseek',
            'key' => env('DEEPSEEK_API_KEY'),
        ],

        'eleven' => [
            'driver' => 'eleven',
            'key' => env('ELEVENLABS_API_KEY'),
        ],

        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY'),
        ],

        'groq' => [
            'driver' => 'groq',
            'key' => env('GROQ_API_KEY'),
        ],

        'jina' => [
            'driver' => 'jina',
            'key' => env('JINA_API_KEY'),
        ],

        'mistral' => [
            'driver' => 'mistral',
            'key' => env('MISTRAL_API_KEY'),
        ],

        'ollama' => [
            'driver' => 'ollama',
            'key' => env('OLLAMA_API_KEY', ''),
            'url' => env('OLLAMA_BASE_URL', 'http://localhost:11434'),
        ],

        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
        ],

        'openrouter' => [
            'driver' => 'openrouter',
            'key' => env('OPENROUTER_API_KEY'),
        ],

        'voyageai' => [
            'driver' => 'voyageai',
            'key' => env('VOYAGEAI_API_KEY'),
        ],

        'xai' => [
            'driver' => 'xai',
            'key' => env('XAI_API_KEY'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Application AI Features
    |--------------------------------------------------------------------------
    |
    | These options configure AI-powered product features in this application.
    | Feature services may override provider/model per use-case.
    |
    */

    'features' => [
        'event_media_extraction' => [
            'provider' => env('AI_EVENT_MEDIA_EXTRACTION_PROVIDER', 'openai'),
            'model' => env('AI_EVENT_MEDIA_EXTRACTION_MODEL'),
            'max_file_size_kb' => (int) env('AI_EVENT_MEDIA_EXTRACTION_MAX_FILE_SIZE_KB', 10240),
            'accepted_mime_types' => [
                'application/pdf',
                'image/jpeg',
                'image/png',
                'image/webp',
            ],
        ],
        'event_cover_generation' => [
            'provider' => env('AI_EVENT_COVER_GENERATION_PROVIDER', 'openai'),
            'model' => env('AI_EVENT_COVER_GENERATION_MODEL', 'gpt-image-2'),
            'quality' => env('AI_EVENT_COVER_GENERATION_QUALITY', 'low'),
            'timeout' => (int) env('AI_EVENT_COVER_GENERATION_TIMEOUT', 120),
            'max_reference_media' => (int) env('AI_EVENT_COVER_GENERATION_MAX_REFERENCE_MEDIA', 6),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Usage Tracking
    |--------------------------------------------------------------------------
    |
    | This section controls the token/cost ledger stored in ai_usage_logs.
    | Primary pricing resolution is database-driven via ai_model_pricings for
    | model/tier/operation-specific rules. The "pricing" config map below is
    | kept as a fallback layer and supports wildcard keys.
    |
    */

    'usage_tracking' => [
        'enabled' => (bool) env('AI_USAGE_TRACKING_ENABLED', true),
        'currency' => env('AI_USAGE_TRACKING_CURRENCY', 'USD'),
        'pricing' => [
            'openai' => [
                'gpt-image-2*' => [
                    'per_image' => (float) env('AI_OPENAI_GPT_IMAGE_2_LOW_LANDSCAPE_PER_IMAGE_USD', 0.005),
                ],
            ],
        ],
    ],

];
