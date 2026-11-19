<?php

declare(strict_types=1);

namespace App\Platform\Api\Frontend;

/**
 * Neutral family identifiers shared by the dispatcher and owner contributors.
 */
final class FrontendFormContractKey
{
    public const string MANIFEST = 'manifest';

    public const string SUBMIT_EVENT = 'submit-event';

    public const string SUBMIT_INSTITUTION = 'submit-institution';

    public const string SUBMIT_PERSON = 'submit-person';

    public const string MOBILE_TELEMETRY = 'mobile-telemetry';

    public const string MEMBERSHIP_APPLICATION = 'membership-application';

    public const string REPORT = 'report';

    public const string GITHUB_ISSUE_REPORT = 'github-issue-report';

    public const string GITHUB_ISSUE_REPORTING = 'github-issue-reporting';

    public const string ACCOUNT_SETTINGS = 'account-settings';

    public const string ADVANCED_EVENT = 'advanced-event';

    public const string INSTITUTION_WORKSPACE = 'institution-workspace';

    private function __construct() {}
}
