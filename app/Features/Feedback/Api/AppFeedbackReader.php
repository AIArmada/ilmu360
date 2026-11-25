<?php

declare(strict_types=1);

namespace App\Features\Feedback\Api;

/**
 * Public reporter-scoped feedback read boundary.
 *
 * Consumers: Feedback/FeatureServiceProvider.php, Contributions/EntryPoints
 */
interface AppFeedbackReader
{
    public function pageForReporter(
        string $reporterId,
        int $perPage = 8,
        int $page = 1,
        ?string $status = null,
        ?string $search = null,
    ): AppFeedbackPage;

    /** @return list<AppFeedbackSnapshot> */
    public function recentForReporter(string $reporterId, int $limit = 5): array;

    /**
     * One feedback snapshot owned by the reporter, or null when the ticket
     * does not exist, is not owned, or the id is not a UUID.
     */
    public function findForReporter(string $reporterId, string $feedbackId): ?AppFeedbackSnapshot;

    /** @return array<string, int> */
    public function countsByStatusForReporter(string $reporterId): array;
}
