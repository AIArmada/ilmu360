<?php

declare(strict_types=1);

namespace App\Features\DonationChannels\Queries;

use App\Features\DonationChannels\Api\DonationChannelLookup;
use App\Models\DonationChannel;
use App\Platform\Admin\AdminResourceUrl;
use App\Shared\Reports\ReportSubject;
use App\Shared\Reports\ReportSubjectType;

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
