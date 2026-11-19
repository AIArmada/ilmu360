<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Queries;

use App\Features\DonationChannels\Api\DonationChannelLookup;
use App\Features\DonationChannels\Models\DonationChannel;
use App\Features\Reports\Api\ReportSubject;
use App\Features\Reports\Api\ReportSubjectType;
use App\Platform\Admin\AdminResourceUrl;

final readonly class EloquentDonationChannelLookup implements DonationChannelLookup
{
    public function findReportSubject(string $id): ?ReportSubject
    {
        if (trim($id) === '') {
            return null;
        }
        $channel = DonationChannel::query()->find($id);
        if (! $channel instanceof DonationChannel) {
            return null;
        }
        $key = (string) $channel->getKey();

        return new ReportSubject(
            type: ReportSubjectType::DonationChannel,
            id: $key,
            label: __('Donation Channel'),
            title: filled($channel->getAttribute('label')) ? (string) $channel->getAttribute('label') : (
                filled($channel->getAttribute('account_name')) ? (string) $channel->getAttribute('account_name') : $key
            ),
            slug: null,
            publicUrl: null,
            adminUrl: AdminResourceUrl::edit('donation-channels', (string) $channel->getRouteKey()),
            imageUrl: null,
        );
    }

    public function resolveReportSubject(string $identifier): ?ReportSubject
    {
        return $this->findReportSubject($identifier);
    }
}
