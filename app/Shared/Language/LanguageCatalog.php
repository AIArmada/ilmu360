<?php

declare(strict_types=1);

namespace App\Shared\Language;

interface LanguageCatalog
{
    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    public function codesIn(array $codes): array;

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    public function idsForCodes(array $codes): array;
}
