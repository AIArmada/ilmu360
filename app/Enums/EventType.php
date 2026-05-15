<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;

enum EventType: string implements HasColor, HasIcon, HasLabel
{
    // Ilmu
    case KuliahCeramah = 'kuliah_ceramah';
    case KelasDaurah = 'kelas_daurah';
    case Talim = 'talim';
    case Forum = 'forum';
    case SeminarKonvensyen = 'seminar_konvensyen';
    case Tazkirah = 'tazkirah';
    case KhutbahJumaat = 'khutbah_jumaat';

    // Ibadah
    case Qiamullail = 'qiamullail';
    case Tahlil = 'tahlil';
    case SolatHajat = 'solat_hajat';

    // Zikir & Doa
    case Zikir = 'zikir';
    case Selawat = 'selawat';
    case DoaSelamat = 'doa_selamat';

    // Tilawah
    case BacaanYasin = 'bacaan_yasin';
    case KhatamQuran = 'khatam_quran';
    case Tilawah = 'tilawah';
    case HafazanQuran = 'hafazan_quran';

    // Komuniti
    case GotongRoyong = 'gotong_royong';
    case Kenduri = 'kenduri';
    case Iftar = 'iftar';
    case Sahur = 'sahur';
    case Korban = 'korban';
    case Aqiqah = 'aqiqah';

    // Umum / Lain-lain
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::KuliahCeramah => __('Kuliah / Ceramah'),
            self::KelasDaurah => __('Kelas / Daurah'),
            self::Talim => __('Ta\'lim'),
            self::Forum => __('Forum'),
            self::SeminarKonvensyen => __('Seminar / Konvensyen'),
            self::Tazkirah => __('Tazkirah'),
            self::KhutbahJumaat => __('Khutbah Jumaat'),
            self::Qiamullail => __('Qiamullail'),
            self::Tahlil => __('Tahlil'),
            self::SolatHajat => __('Solat Hajat'),
            self::Zikir => __('Zikir'),
            self::Selawat => __('Selawat'),
            self::DoaSelamat => __('Doa Selamat'),
            self::BacaanYasin => __('Bacaan Yasin'),
            self::KhatamQuran => __('Khatam Al-Quran'),
            self::Tilawah => __('Tilawah Al-Quran'),
            self::HafazanQuran => __('Hafazan Al-Quran'),
            self::GotongRoyong => __('Gotong Royong'),
            self::Kenduri => __('Kenduri'),
            self::Iftar => __('Iftar / Berbuka Puasa'),
            self::Sahur => __('Sahur'),
            self::Korban => __('Korban'),
            self::Aqiqah => __('Aqiqah'),
            self::Other => __('Lain-lain'),
        };
    }

    public function getGroup(): string
    {
        return match ($this) {
            self::KuliahCeramah, self::KelasDaurah, self::Talim, self::Forum, self::SeminarKonvensyen, self::Tazkirah, self::KhutbahJumaat => __('Ilmu'),
            self::Qiamullail, self::Tahlil, self::SolatHajat => __('Ibadah'),
            self::Zikir, self::Selawat, self::DoaSelamat => __('Zikir & Doa'),
            self::BacaanYasin, self::KhatamQuran, self::Tilawah, self::HafazanQuran => __('Tilawah'),
            self::GotongRoyong, self::Kenduri, self::Iftar, self::Sahur, self::Korban, self::Aqiqah => __('Komuniti'),
            self::Other => __('Lain-lain'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::KuliahCeramah, self::KelasDaurah, self::Talim, self::Forum, self::SeminarKonvensyen, self::Tazkirah, self::KhutbahJumaat => 'info',
            self::Qiamullail, self::Tahlil, self::SolatHajat => 'success',
            self::Zikir, self::Selawat, self::DoaSelamat => 'primary',
            self::BacaanYasin, self::KhatamQuran, self::Tilawah, self::HafazanQuran => 'success',
            self::GotongRoyong, self::Kenduri, self::Iftar, self::Sahur, self::Korban, self::Aqiqah => 'warning',
            self::Other => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::KuliahCeramah => 'heroicon-m-book-open',
            self::KelasDaurah => 'heroicon-m-academic-cap',
            self::Talim => 'heroicon-m-book-open',
            self::Forum => 'heroicon-m-chat-bubble-left-right',
            self::SeminarKonvensyen => 'heroicon-m-academic-cap',
            self::Tazkirah => 'heroicon-m-megaphone',
            self::KhutbahJumaat => 'heroicon-m-megaphone',
            self::Qiamullail, self::SolatHajat => 'heroicon-m-moon',
            self::Tahlil => 'heroicon-m-heart',
            self::Zikir, self::Selawat => 'heroicon-m-sparkles',
            self::DoaSelamat => 'heroicon-m-hand-raised',
            self::BacaanYasin, self::KhatamQuran, self::Tilawah, self::HafazanQuran => 'heroicon-m-book-open',
            self::GotongRoyong => 'heroicon-m-user-group',
            self::Kenduri, self::Iftar, self::Sahur => 'heroicon-m-cake',
            self::Korban, self::Aqiqah => 'heroicon-m-gift',
            self::Other => 'heroicon-m-ellipsis-horizontal-circle',
        };
    }

    public function isCommunity(): bool
    {
        return match ($this) {
            self::GotongRoyong, self::Kenduri, self::Iftar, self::Sahur, self::Korban, self::Aqiqah => true,
            default => false,
        };
    }

    public function requiresSpeakerByDefault(): bool
    {
        return match ($this) {
            self::KuliahCeramah,
            self::KelasDaurah,
            self::Talim,
            self::Forum,
            self::SeminarKonvensyen,
            self::Tazkirah => true,
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    public function suggestedKeyPersonRoles(): array
    {
        return match ($this) {
            self::KuliahCeramah,
            self::KelasDaurah,
            self::Talim,
            self::SeminarKonvensyen,
            self::Tazkirah => [EventKeyPersonRole::Speaker->value, EventKeyPersonRole::Moderator->value],
            self::Forum => [EventKeyPersonRole::Speaker->value, EventKeyPersonRole::Moderator->value],
            self::KhutbahJumaat => [EventKeyPersonRole::Khatib->value, EventKeyPersonRole::Imam->value, EventKeyPersonRole::Bilal->value],
            self::Qiamullail,
            self::Tahlil,
            self::SolatHajat => [EventKeyPersonRole::Imam->value],
            self::Zikir,
            self::Selawat,
            self::DoaSelamat,
            self::BacaanYasin,
            self::KhatamQuran,
            self::Tilawah,
            self::HafazanQuran => [EventKeyPersonRole::Imam->value, EventKeyPersonRole::Bilal->value],
            self::GotongRoyong,
            self::Kenduri,
            self::Iftar,
            self::Sahur,
            self::Korban,
            self::Aqiqah => [EventKeyPersonRole::PersonInCharge->value],
            self::Other => [EventKeyPersonRole::Speaker->value, EventKeyPersonRole::PersonInCharge->value],
        };
    }
}
