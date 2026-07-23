<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum CredentialType: string implements HasLabel
{
    case AcademicDegree = 'academic_degree';
    case ProfessionalLicense = 'professional_license';
    case Certification = 'certification';

    public function getLabel(): string
    {
        return match ($this) {
            self::AcademicDegree => __('Academic Degree'),
            self::ProfessionalLicense => __('Professional License'),
            self::Certification => __('Certification'),
        };
    }
}
