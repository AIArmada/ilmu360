<?php

declare(strict_types=1);

namespace App\Enums\TaxonomyTerm;

enum DomainTermCode: string
{
    case AgamaKerohanian = 'agama-kerohanian';
    case Pendidikan = 'pendidikan';
    case SainsMatematik = 'sains-matematik';
    case TeknologiIt = 'teknologi-it';
    case KerjayaKemahiran = 'kerjaya-kemahiran';
    case Kesihatan = 'kesihatan';
    case KeluargaMasyarakat = 'keluarga-masyarakat';
    case LainLain = 'lain-lain';

    public function label(): string
    {
        return match ($this) {
            self::AgamaKerohanian => 'Agama & Kerohanian',
            self::Pendidikan => 'Pendidikan',
            self::SainsMatematik => 'Sains & Matematik',
            self::TeknologiIt => 'Teknologi & IT',
            self::KerjayaKemahiran => 'Kerjaya & Kemahiran',
            self::Kesihatan => 'Kesihatan',
            self::KeluargaMasyarakat => 'Keluarga & Masyarakat',
            self::LainLain => 'Lain-lain / Tulis sendiri',
        };
    }
}
