<?php

namespace App\Ai\Agents;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\StringType;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Promptable;
use Stringable;

class EventMediaExtractionAgent implements Agent, Conversational, HasStructuredOutput, HasTools
{
    use Promptable;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        protected array $context = [],
    ) {}

    /**
     * Get the instructions that the agent should follow.
     */
    public function instructions(): Stringable|string
    {
        $context = json_encode(
            $this->context,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return <<<TXT
You extract structured event data from a single event source file (poster image or PDF).
Follow these rules strictly:
- Use only details that are explicitly visible in the uploaded file.
- If a field is missing or ambiguous, return null (or [] for arrays).
- Do not invent speakers, organizers, venues, URLs, dates, or times.
- Use YYYY-MM-DD for event_date.
- Use 24-hour HH:MM format for custom_time and end_time.
- Keep description concise and factual (no markdown, no bullet symbols).
- For event_type, prayer_time, event_format, visibility, gender, and age_group, return only allowed enum values.
- For language_codes, return only supported codes from the context.
- For domain_tag_ids and source_tag_ids, return only IDs from the context.
- For discipline_tags and issue_tags, return short labels or keywords in Malay when possible.

Application context and allowed options:
{$context}
TXT;
    }

    /**
     * Get the list of messages comprising the conversation so far.
     *
     * @return array<int, mixed>
     */
    public function messages(): array
    {
        return [];
    }

    /**
     * Get the tools available to the agent.
     *
     * @return array<int, mixed>
     */
    public function tools(): array
    {
        return [];
    }

    /**
     * Get the agent's structured output schema definition.
     *
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        $enumString = function (string $contextKey) use ($schema): StringType {
            $type = $schema->string();
            $rawValues = $this->context[$contextKey] ?? [];

            if (! is_array($rawValues)) {
                $rawValues = [];
            }

            $values = collect($rawValues)
                ->filter(fn (mixed $value): bool => is_string($value) && filled($value))
                ->values()
                ->all();

            if ($values !== []) {
                $type->enum($values);
            }

            return $type;
        };

        $domainTagOptions = $this->context['domain_tag_options'] ?? [];
        if (! is_array($domainTagOptions)) {
            $domainTagOptions = [];
        }

        $domainTagIds = collect(array_keys($domainTagOptions))
            ->filter(fn (mixed $value): bool => is_string($value) && filled($value))
            ->values()
            ->all();

        $sourceTagOptions = $this->context['source_tag_options'] ?? [];
        if (! is_array($sourceTagOptions)) {
            $sourceTagOptions = [];
        }

        $sourceTagIds = collect(array_keys($sourceTagOptions))
            ->filter(fn (mixed $value): bool => is_string($value) && filled($value))
            ->values()
            ->all();

        $domainTagIdType = $schema->string();
        if ($domainTagIds !== []) {
            $domainTagIdType->enum($domainTagIds);
        }

        $sourceTagIdType = $schema->string();
        if ($sourceTagIds !== []) {
            $sourceTagIdType->enum($sourceTagIds);
        }

        return [
            'title' => $schema->string()->max(255)->nullable(),
            'description' => $schema->string()->max(5000)->nullable(),
            'event_date' => $schema->string()->format('date')->nullable(),
            'prayer_time' => $enumString('prayer_time_values')->nullable(),
            'custom_time' => $schema->string()->pattern('^([01]\d|2[0-3]):[0-5]\d$')->nullable(),
            'end_time' => $schema->string()->pattern('^([01]\d|2[0-3]):[0-5]\d$')->nullable(),
            'event_type' => $schema->array()
                ->items($enumString('event_type_values'))
                ->max(3)
                ->nullable(),
            'event_format' => $enumString('event_format_values')->nullable(),
            'visibility' => $enumString('visibility_values')->nullable(),
            'event_url' => $schema->string()->format('url')->nullable(),
            'live_url' => $schema->string()->format('url')->nullable(),
            'gender' => $enumString('gender_values')->nullable(),
            'age_group' => $schema->array()
                ->items($enumString('age_group_values'))
                ->max(5)
                ->nullable(),
            'children_allowed' => $schema->boolean()->nullable(),
            'is_muslim_only' => $schema->boolean()->nullable(),
            'language_codes' => $schema->array()
                ->items($enumString('language_codes'))
                ->max(6)
                ->nullable(),
            'domain_tag_ids' => $schema->array()
                ->items($domainTagIdType)
                ->max(3)
                ->nullable(),
            'source_tag_ids' => $schema->array()
                ->items($sourceTagIdType)
                ->max(5)
                ->nullable(),
            'discipline_tags' => $schema->array()
                ->items($schema->string()->max(120))
                ->max(5)
                ->nullable(),
            'issue_tags' => $schema->array()
                ->items($schema->string()->max(120))
                ->max(5)
                ->nullable(),
        ];
    }
}
