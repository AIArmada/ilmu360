<?php

declare(strict_types=1);

namespace App\Actions\Reports;

use Lorisleiva\Actions\Concerns\AsAction;

class ResolveReportCategoryOptionsAction
{
    use AsAction;

    /**
     * @return array<string, string>
     */
    public function handle(?string $subjectType = null): array
    {
        if (! is_string($subjectType) || $subjectType === '') {
            return $this->allOptions();
        }

        return $this->optionsForSubjectType($subjectType);
    }

    /**
     * @return list<string>
     */
    public function validKeys(?string $subjectType = null): array
    {
        return array_keys($this->handle($subjectType));
    }

    /**
     * @return array<string, string>
     */
    private function allOptions(): array
    {
        $options = [];

        foreach (['event', 'institution', 'person', 'reference', 'donation_channel'] as $subjectType) {
            foreach ($this->optionsForSubjectType($subjectType) as $key => $label) {
                if (! array_key_exists($key, $options)) {
                    $options[$key] = $label;
                }
            }
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private function optionsForSubjectType(string $subjectType): array
    {
        return match ($subjectType) {
            'event' => [
                'wrong_info' => __('Maklumat tidak tepat'),
                'cancelled_not_updated' => __('Dibatalkan tetapi belum dikemas kini'),
                'inappropriate_content' => __('Kandungan tidak sesuai'),
                'other' => __('Lain-lain'),
            ],
            'institution' => [
                'wrong_info' => __('Maklumat tidak tepat'),
                'fake_institution' => __('Institusi palsu'),
                'other' => __('Lain-lain'),
            ],
            'person' => [
                'wrong_info' => __('Maklumat tidak tepat'),
                'duplicate_person' => __('Profil pendua'),
                'impersonation_or_scam' => __('Penyamaran atau penipuan'),
                'fake_person' => __('Penceramah palsu'),
                'other' => __('Lain-lain'),
            ],
            'reference' => [
                'wrong_info' => __('Maklumat tidak tepat'),
                'fake_reference' => __('Rujukan palsu'),
                'other' => __('Lain-lain'),
            ],
            'donation_channel' => [
                'wrong_info' => __('Maklumat tidak tepat'),
                'donation_scam' => __('Penipuan saluran derma'),
                'other' => __('Lain-lain'),
            ],
            default => [
                'other' => __('Lain-lain'),
            ],
        };
    }
}
