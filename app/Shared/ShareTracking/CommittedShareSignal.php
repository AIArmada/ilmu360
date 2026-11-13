<?php

declare(strict_types=1);

namespace App\Shared\ShareTracking;

/**
 * Canonical vocabulary for the committed-share Signals pipeline.
 *
 * Authority is the committed outbound-share touchpoint; the trusted Signals
 * event and the durable cohort projection both carry this identity. The
 * reader name owns exactly one cursor row and one poison-skip set, and the
 * stage values are the only projection stages the cohort model accepts.
 */
final class CommittedShareSignal
{
    public const string READER_NAME = 'committed-share-cohort';

    public const string EVENT_NAME = 'share.committed_share';

    public const string TOUCHPOINT_TYPE = 'outbound_share';

    /**
     * Stored conversion statuses that count as participation.
     *
     * @var list<string>
     */
    public const array VALID_CONVERSION_STATUSES = ['qualified', 'approved', 'paid'];

    public const string STAGE_OBSERVED = 'observed';

    public const string STAGE_PARTICIPATED = 'participated';
}
