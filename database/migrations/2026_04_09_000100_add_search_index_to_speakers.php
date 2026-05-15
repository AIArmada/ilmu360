<?php

use App\Enums\Honorific;
use App\Enums\PostNominal;
use App\Enums\PreNominal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('speakers', function (Blueprint $table) {
            if (! Schema::hasColumn('speakers', 'searchable_name')) {
                $table->string('searchable_name', 512)->default('')->index()->after('name');
            }
        });

        if (! Schema::hasTable('speaker_search_terms')) {
            Schema::create('speaker_search_terms', function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->foreignUuid('speaker_id')->index();
                $table->string('term', 120)->index();
                $table->index(['speaker_id', 'term']);
            });
        }

        DB::table('speakers')
            ->select(['id', 'name', 'honorific', 'pre_nominal', 'post_nominal'])
            ->orderBy('id')
            ->chunk(100, function ($speakers): void {
                foreach ($speakers as $speaker) {
                    $honorific = $this->decodeStringArray($speaker->honorific ?? null);
                    $preNominal = $this->decodeStringArray($speaker->pre_nominal ?? null);
                    $postNominal = $this->decodeStringArray($speaker->post_nominal ?? null);

                    $searchableName = $this->buildSearchableName(
                        is_string($speaker->name ?? null) ? $speaker->name : null,
                        $honorific,
                        $preNominal,
                        $postNominal,
                    );

                    DB::table('speakers')
                        ->where('id', $speaker->id)
                        ->update(['searchable_name' => $searchableName]);

                    DB::table('speaker_search_terms')
                        ->where('speaker_id', $speaker->id)
                        ->delete();

                    $terms = $this->buildSearchTerms(
                        is_string($speaker->name ?? null) ? $speaker->name : null,
                        $honorific,
                        $preNominal,
                        $postNominal,
                    );

                    if ($terms === []) {
                        continue;
                    }

                    DB::table('speaker_search_terms')->insert(
                        collect($terms)
                            ->map(fn (string $term): array => [
                                'id' => (string) Str::uuid(),
                                'speaker_id' => (string) $speaker->id,
                                'term' => $term,
                            ])
                            ->all()
                    );
                }
            });
    }

    /**
     * @param  iterable<int, string>|string|null  $honorific
     * @param  iterable<int, string>|string|null  $preNominal
     * @param  iterable<int, string>|string|null  $postNominal
     */
    private function buildSearchableName(
        ?string $name,
        iterable|string|null $honorific = null,
        iterable|string|null $preNominal = null,
        iterable|string|null $postNominal = null,
    ): string {
        $formattedName = $this->formatDisplayedName($name, $honorific, $preNominal, $postNominal);
        $rawDecorations = collect([
            ...$this->normalizedStringValues($honorific),
            ...$this->normalizedStringValues($preNominal),
            ...$this->normalizedStringValues($postNominal),
        ])
            ->map(static fn (string $value): string => str_replace(['_', '-'], ' ', $value))
            ->implode(' ');

        return $this->normalizeText(implode(' ', array_filter([
            trim($formattedName),
            trim((string) $name),
            trim($rawDecorations),
        ])));
    }

    /**
     * @param  iterable<int, string>|string|null  $honorific
     * @param  iterable<int, string>|string|null  $preNominal
     * @param  iterable<int, string>|string|null  $postNominal
     * @return list<string>
     */
    private function buildSearchTerms(
        ?string $name,
        iterable|string|null $honorific = null,
        iterable|string|null $preNominal = null,
        iterable|string|null $postNominal = null,
    ): array {
        $searchableName = $this->buildSearchableName($name, $honorific, $preNominal, $postNominal);

        if ($searchableName === '') {
            return [];
        }

        /** @var list<string> $terms */
        $terms = collect(explode(' ', $searchableName))
            ->filter(static fn (string $term): bool => $term !== '')
            ->unique()
            ->values()
            ->all();

        return $terms;
    }

    /**
     * @param  iterable<int, string>|string|null  $honorific
     * @param  iterable<int, string>|string|null  $preNominal
     * @param  iterable<int, string>|string|null  $postNominal
     */
    private function formatDisplayedName(
        ?string $name,
        iterable|string|null $honorific = null,
        iterable|string|null $preNominal = null,
        iterable|string|null $postNominal = null,
    ): string {
        $leadingPreNominalLabels = $this->labelsFromPreNominalCases($this->leadingPreNominalCases($preNominal));
        $honorificLabels = $this->labelsFromHonorificCases($this->orderedHonorificCases($honorific));
        $trailingPreNominalLabels = $this->labelsFromPreNominalCases($this->trailingPreNominalCases($preNominal));

        $parts = array_filter([
            $leadingPreNominalLabels,
            $honorificLabels,
            $trailingPreNominalLabels,
            trim((string) $name),
        ], filled(...));

        $formatted = trim(implode(' ', $parts));
        $postNominalValues = $this->orderedPostNominalValues($postNominal);

        if ($postNominalValues !== []) {
            $formatted = trim($formatted.', '.implode(', ', $postNominalValues));
        }

        return $formatted;
    }

    /**
     * @param  iterable<int, string>|string|null  $values
     * @return list<Honorific>
     */
    private function orderedHonorificCases(iterable|string|null $values): array
    {
        $cases = [];

        foreach ($this->normalizedStringValues($values) as $value) {
            $case = Honorific::tryFrom($value);

            if ($case instanceof Honorific) {
                $cases[$case->value] = $case;
            }
        }

        $orderedCases = array_values($cases);

        usort($orderedCases, fn (Honorific $left, Honorific $right): int => $this->honorificSortOrder($left) <=> $this->honorificSortOrder($right));

        return $orderedCases;
    }

    /**
     * @param  iterable<int, string>|string|null  $values
     * @return list<PreNominal>
     */
    private function orderedPreNominalCases(iterable|string|null $values): array
    {
        $cases = [];

        foreach ($this->normalizedStringValues($values) as $value) {
            $case = PreNominal::tryFrom($value);

            if ($case instanceof PreNominal) {
                $cases[$case->value] = $case;
            }
        }

        $orderedCases = array_values($cases);

        usort($orderedCases, fn (PreNominal $left, PreNominal $right): int => $this->preNominalSortOrder($left) <=> $this->preNominalSortOrder($right));

        return $orderedCases;
    }

    /**
     * @param  iterable<int, string>|string|null  $values
     * @return list<PreNominal>
     */
    private function leadingPreNominalCases(iterable|string|null $values): array
    {
        return array_values(array_filter(
            $this->orderedPreNominalCases($values),
            static fn (PreNominal $case): bool => in_array($case, [PreNominal::Prof, PreNominal::ProfMadya], true),
        ));
    }

    /**
     * @param  iterable<int, string>|string|null  $values
     * @return list<PreNominal>
     */
    private function trailingPreNominalCases(iterable|string|null $values): array
    {
        return array_values(array_filter(
            $this->orderedPreNominalCases($values),
            static fn (PreNominal $case): bool => ! in_array($case, [PreNominal::Prof, PreNominal::ProfMadya], true),
        ));
    }

    /**
     * @param  list<Honorific>  $cases
     */
    private function labelsFromHonorificCases(array $cases): ?string
    {
        if ($cases === []) {
            return null;
        }

        return collect($cases)
            ->map(static fn (Honorific $case): string => $case->getLabel())
            ->implode(' ');
    }

    /**
     * @param  list<PreNominal>  $cases
     */
    private function labelsFromPreNominalCases(array $cases): ?string
    {
        if ($cases === []) {
            return null;
        }

        return collect($cases)
            ->map(static fn (PreNominal $case): string => $case->getLabel())
            ->implode(' ');
    }

    /**
     * @param  iterable<int, string>|string|null  $values
     * @return list<string>
     */
    private function orderedPostNominalValues(iterable|string|null $values): array
    {
        $uniqueValues = [];

        foreach ($this->normalizedStringValues($values) as $value) {
            $uniqueValues[$value] = $value;
        }

        $sortableValues = [];

        foreach (array_values($uniqueValues) as $index => $value) {
            $sortableValues[] = [
                'value' => $this->postNominalDisplayValue($value),
                'order' => $this->postNominalSortOrder($value),
                'index' => $index,
            ];
        }

        usort($sortableValues, static function (array $left, array $right): int {
            $orderComparison = $left['order'] <=> $right['order'];

            if ($orderComparison !== 0) {
                return $orderComparison;
            }

            return $left['index'] <=> $right['index'];
        });

        return array_values(array_map(
            static fn (array $entry): string => $entry['value'],
            $sortableValues,
        ));
    }

    private function honorificSortOrder(Honorific $honorific): int
    {
        return match ($honorific) {
            Honorific::Tun,
            Honorific::TohPuan => 10,
            Honorific::TanSri,
            Honorific::PuanSri => 20,
            Honorific::DatukSeriUtama => 30,
            Honorific::DatukPatinggi => 40,
            Honorific::DatukAmar => 50,
            Honorific::DatukSeriPanglima => 60,
            Honorific::DatukSeri,
            Honorific::DatoSri,
            Honorific::DatukPaduka,
            Honorific::DatinPaduka => 70,
            Honorific::DatukWira,
            Honorific::DatoWira,
            Honorific::DatoSetia => 80,
            Honorific::Datuk,
            Honorific::Dato,
            Honorific::Datin => 90,
        };
    }

    private function preNominalSortOrder(PreNominal $preNominal): int
    {
        return match ($preNominal) {
            PreNominal::Prof => 10,
            PreNominal::Syeikh => 20,
            PreNominal::SyeikhulMaqari => 21,
            PreNominal::Maulana => 22,
            PreNominal::Habib => 23,
            PreNominal::TuanGuru => 24,
            PreNominal::Pendeta => 25,
            PreNominal::Ustaz => 26,
            PreNominal::Ustazah => 27,
            PreNominal::ImamMuda => 28,
            PreNominal::PU,
            PreNominal::Dai => 29,
            PreNominal::Hafiz => 30,
            PreNominal::Hafizah => 31,
            PreNominal::Qari => 32,
            PreNominal::Qariah => 33,
            PreNominal::Mufti => 34,
            PreNominal::Kadi => 35,
            PreNominal::ProfMadya => 40,
            PreNominal::Ir => 50,
            PreNominal::Ar => 51,
            PreNominal::Dr => 60,
            PreNominal::Hj => 61,
            PreNominal::Hjh => 62,
        };
    }

    private function postNominalSortOrder(string $value): int
    {
        $case = PostNominal::tryFrom($value);

        return match ($case) {
            PostNominal::PhD => 10,
            PostNominal::MSc => 20,
            PostNominal::MA => 21,
            PostNominal::BSc => 30,
            PostNominal::BA => 31,
            PostNominal::Lc => 32,
            PostNominal::Hons => 33,
            PostNominal::Dpl => 40,
            null => 1_000,
        };
    }

    private function postNominalDisplayValue(string $value): string
    {
        return PostNominal::tryFrom($value)?->getLabel() ?? $value;
    }

    /**
     * @return list<string>
     */
    private function decodeStringArray(mixed $value): array
    {
        if (! is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded)
            ? array_values(array_filter(array_map(
                static fn (mixed $item): ?string => is_string($item) && trim($item) !== '' ? trim($item) : null,
                $decoded,
            )))
            : [];
    }

    /**
     * @param  iterable<int, string>|string|null  $values
     * @return list<string>
     */
    private function normalizedStringValues(iterable|string|null $values): array
    {
        if (is_string($values)) {
            $trimmed = trim($values);

            return $trimmed !== '' ? [$trimmed] : [];
        }

        if (! is_iterable($values)) {
            return [];
        }

        /** @var list<string> $normalized */
        $normalized = collect($values)
            ->map(static fn (string $value): ?string => trim($value) !== '' ? trim($value) : null)
            ->filter()
            ->values()
            ->all();

        return $normalized;
    }

    private function normalizeText(string $value): string
    {
        return (string) Str::of($value)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9\s]+/u', ' ')
            ->replaceMatches('/\s+/u', ' ')
            ->trim();
    }
};
