<?php

namespace App\Platform\Ui\Forms;

class FormOptions
{
    /**
     * @param  array<int|string, string>  $options
     * @return array<string, string>
     */
    public static function translatedOptions(array $options): array
    {
        $translated = [];

        foreach ($options as $value => $label) {
            $translated[(string) $value] = __($label);
        }

        return $translated;
    }
}
