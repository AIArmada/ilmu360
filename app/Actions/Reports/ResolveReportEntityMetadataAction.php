<?php

namespace App\Actions\Reports;

use App\Models\DonationChannel;
use App\Models\Event;
use App\Models\Institution;
use App\Models\Reference;
use App\Models\Speaker;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolveReportEntityMetadataAction
{
    use AsAction;

    /**
     * @return array{label: string, model_class: class-string}
     */
    public function handle(string $entityType): array
    {
        return match ($entityType) {
            'event' => [
                'label' => __('Event'),
                'model_class' => Event::class,
            ],
            'institution' => [
                'label' => __('Institution'),
                'model_class' => Institution::class,
            ],
            'speaker' => [
                'label' => __('Speaker'),
                'model_class' => Speaker::class,
            ],
            'reference' => [
                'label' => __('Reference'),
                'model_class' => Reference::class,
            ],
            'donation_channel' => [
                'label' => __('Donation Channel'),
                'model_class' => DonationChannel::class,
            ],
            default => throw new InvalidArgumentException("Unsupported entity type [{$entityType}]"),
        };
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->validKeys() as $entityType) {
            $options[$entityType] = $this->handle($entityType)['label'];
        }

        return $options;
    }

    /**
     * @return list<string>
     */
    public function validKeys(): array
    {
        return ['event', 'institution', 'speaker', 'reference', 'donation_channel'];
    }
}
