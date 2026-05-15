<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum Honorific: string implements HasLabel
{
    // Highest Federal Honours
    case Tun = 'tun';
    case TohPuan = 'toh_puan';

    // Tan Sri Level
    case TanSri = 'tan_sri';
    case PuanSri = 'puan_sri';

    // Datuk/Dato' Base Titles
    case Datuk = 'datuk';
    case Dato = 'dato';
    case Datin = 'datin';

    // Higher Grade Datuk/Dato'
    case DatukSeri = 'datuk_seri';
    case DatoSri = 'dato_sri';
    case DatukPaduka = 'datuk_paduka';
    case DatinPaduka = 'datin_paduka';

    // Warrior Distinction
    case DatukWira = 'datuk_wira';
    case DatoWira = 'dato_wira';

    // State Distinction
    case DatoSetia = 'dato_setia';

    // Sarawak Specific
    case DatukAmar = 'datuk_amar';
    case DatukPatinggi = 'datuk_patinggi';

    // Sabah Specific
    case DatukSeriPanglima = 'datuk_seri_panglima';

    // State Highest Honours
    case DatukSeriUtama = 'datuk_seri_utama';

    public function getLabel(): string
    {
        return match ($this) {
            // Highest Federal Honours
            self::Tun => __('Tun'),
            self::TohPuan => __('Toh Puan'),

            // Tan Sri Level
            self::TanSri => __('Tan Sri'),
            self::PuanSri => __('Puan Sri'),

            // Datuk/Dato' Base Titles
            self::Datuk => __('Datuk'),
            self::Dato => __('Dato\''),
            self::Datin => __('Datin'),

            // Higher Grade Datuk/Dato'
            self::DatukSeri => __('Datuk Seri'),
            self::DatoSri => __('Dato\' Sri'),
            self::DatukPaduka => __('Datuk Paduka'),
            self::DatinPaduka => __('Datin Paduka'),

            // Warrior Distinction
            self::DatukWira => __('Datuk Wira'),
            self::DatoWira => __('Dato\' Wira'),

            // State Distinction
            self::DatoSetia => __('Dato\' Setia'),

            // Sarawak Specific
            self::DatukAmar => __('Datuk Amar'),
            self::DatukPatinggi => __('Datuk Patinggi'),

            // Sabah Specific
            self::DatukSeriPanglima => __('Datuk Seri Panglima'),

            // State Highest Honours
            self::DatukSeriUtama => __('Datuk Seri Utama'),
        };
    }
}
