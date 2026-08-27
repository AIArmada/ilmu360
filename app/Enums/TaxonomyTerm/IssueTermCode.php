<?php

declare(strict_types=1);

namespace App\Enums\TaxonomyTerm;

enum IssueTermCode: string
{
    case Kepimpinan = 'kepimpinan';
    case Keluarga = 'keluarga';
    case Ekonomi = 'ekonomi';
    case Belia = 'belia';
    case Masyarakat = 'masyarakat';
    case Kesihatan = 'kesihatan';
    case Pendidikan = 'pendidikan';
    case AlamSekitar = 'alam-sekitar';
    case Antirasuah = 'antirasuah';

    public function label(): string
    {
        return match ($this) {
            self::Kepimpinan => 'Kepimpinan',
            self::Keluarga => 'Keluarga',
            self::Ekonomi => 'Ekonomi Islam',
            self::Belia => 'Belia & Remaja',
            self::Masyarakat => 'Masyarakat',
            self::Kesihatan => 'Kesihatan',
            self::Pendidikan => 'Pendidikan',
            self::AlamSekitar => 'Alam Sekitar',
            self::Antirasuah => 'Antirasuah',
        };
    }
}
