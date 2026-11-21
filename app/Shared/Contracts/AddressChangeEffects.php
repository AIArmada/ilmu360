<?php

declare(strict_types=1);

namespace App\Shared\Contracts;

interface AddressChangeEffects
{
    /**
     * @param  list<array{morph_type: string, id: string, name: string, event_ids: list<string>}>  $subjects
     * @param  list<string>  $morphTypes
     */
    public function apply(array $subjects, array $morphTypes): void;
}
