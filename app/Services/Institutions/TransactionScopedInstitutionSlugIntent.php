<?php

declare(strict_types=1);

namespace App\Services\Institutions;

use App\Contracts\InstitutionSlugIntent;
use App\Models\Institution;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseTransactionRecord;
use Illuminate\Database\DatabaseTransactionsManager;
use RuntimeException;
use WeakMap;

/**
 * Transaction-record-scoped institution slug intent.
 *
 * Protection sets live in a WeakMap keyed by the actual current
 * DatabaseTransactionRecord, so intent dies with its record: the manager
 * retains every relevant record while outer-commit callbacks run, then
 * releases them, and rollback drops descendant records the same way.
 * Values hold plain ID sets only — never the record — so cleanup cannot
 * leak through a strong reference.
 */
final class TransactionScopedInstitutionSlugIntent implements InstitutionSlugIntent
{
    /**
     * @var WeakMap<DatabaseTransactionRecord, array<string, true>>
     */
    private WeakMap $protectedIds;

    public function __construct(
        private readonly Application $app,
    ) {
        $this->protectedIds = new WeakMap;
    }

    public function protect(string $institutionId): void
    {
        $record = $this->currentRecord();

        if (! $record instanceof DatabaseTransactionRecord) {
            throw new RuntimeException('Institution slug intent requires an active database transaction.');
        }

        $protected = $this->protectedIds[$record] ?? [];
        $protected[$institutionId] = true;
        $this->protectedIds[$record] = $protected;
    }

    public function isProtected(string $institutionId): bool
    {
        foreach ($this->protectedIds as $protected) {
            if (isset($protected[$institutionId])) {
                return true;
            }
        }

        return false;
    }

    private function currentRecord(): ?DatabaseTransactionRecord
    {
        $manager = $this->app->make('db.transactions');

        if (! $manager instanceof DatabaseTransactionsManager) {
            return null;
        }

        $connection = (new Institution)->getConnection()->getName();

        $record = $manager->callbackApplicableTransactions()
            ->filter(fn (DatabaseTransactionRecord $record): bool => $record->connection === $connection)
            ->last();

        return $record instanceof DatabaseTransactionRecord ? $record : null;
    }
}
