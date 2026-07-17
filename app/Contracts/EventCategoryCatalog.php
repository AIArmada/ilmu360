<?php

declare(strict_types=1);

namespace App\Contracts;

use AIArmada\Events\Models\EventTerm;

interface EventCategoryCatalog
{
    public const string TAXONOMY_CODE = 'event_category';

    public function taxonomyId(): ?string;

    /** @return list<array<string, mixed>> */
    public function tree(): array;

    /** @return array<string, string> */
    public function options(): array;

    /**
     * @param  list<mixed>  $termIds
     * @return array<int, string>
     */
    public function validateTermIds(array $termIds): array;

    /**
     * @param  list<mixed>  $termIds
     * @return array<int, string>
     */
    public function validTermIds(array $termIds): array;

    /**
     * @param  list<string>  $termIds
     * @return array<int, string>
     */
    public function descendantIds(array $termIds): array;

    /**
     * @param  list<string>  $termIds
     * @return array<int, EventTerm>
     */
    public function terms(array $termIds): array;
}
