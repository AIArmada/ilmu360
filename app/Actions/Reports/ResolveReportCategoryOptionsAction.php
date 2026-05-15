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

        foreach (['event', 'institution', 'speaker', 'reference', 'donation_channel'] as $subjectType) {
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
                'wrong_info' => __('Wrong information'),
                'cancelled_not_updated' => __('Cancelled but not updated'),
                'inappropriate_content' => __('Inappropriate content'),
                'other' => __('Other'),
            ],
            'institution' => [
                'wrong_info' => __('Wrong information'),
                'fake_institution' => __('Fake institution'),
                'other' => __('Other'),
            ],
            'speaker' => [
                'wrong_info' => __('Wrong information'),
                'fake_speaker' => __('Fake speaker'),
                'other' => __('Other'),
            ],
            'reference' => [
                'wrong_info' => __('Wrong information'),
                'fake_reference' => __('Fake reference'),
                'other' => __('Other'),
            ],
            'donation_channel' => [
                'wrong_info' => __('Wrong information'),
                'donation_scam' => __('Donation channel scam'),
                'other' => __('Other'),
            ],
            default => [
                'other' => __('Other'),
            ],
        };
    }
}
