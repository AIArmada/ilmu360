<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * @return list<array<string, string>>
 */
function readRefactorLedgerCsv(string $path): array
{
    $handle = fopen($path, 'rb');

    if ($handle === false) {
        throw new RuntimeException("Unable to open ledger [{$path}].");
    }

    try {
        $header = fgetcsv($handle, null, ',', '"', '');

        if ($header === false) {
            throw new RuntimeException("Ledger [{$path}] has no header row.");
        }

        $header = array_map(static fn (mixed $value): string => (string) $value, $header);
        $columnCount = count($header);
        $rows = [];

        while (($values = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if ($values === [null]) {
                continue;
            }

            $values = array_slice(
                array_pad(
                    array_map(static fn (mixed $value): string => (string) $value, $values),
                    $columnCount,
                    '',
                ),
                0,
                $columnCount,
            );

            $row = array_combine($header, $values);

            if ($row === false) {
                throw new RuntimeException("Ledger [{$path}] contains a malformed row.");
            }

            $rows[] = $row;
        }

        return $rows;
    } finally {
        fclose($handle);
    }
}

it('records a resolvable verified commit for every migration ledger row', function (): void {
    $root = dirname(__DIR__, 3);
    $rows = readRefactorLedgerCsv($root.'/tasks/feature-refactor-migration-ledger.csv');

    expect($rows)->toHaveCount(378);

    $invalid = [];

    foreach ($rows as $row) {
        $commit = $row['verified_commit'];

        if ($commit === 'PENDING_COMMIT' || preg_match('/^[0-9a-f]{7,40}$/', $commit) !== 1) {
            $invalid[] = "{$row['destination']} => {$commit}";
        }
    }

    expect($invalid)->toBeEmpty();

    $shallow = new Process(['git', 'rev-parse', '--is-shallow-repository'], $root, ['GIT_OPTIONAL_LOCKS' => '0']);
    $shallow->setTimeout(30);
    $shallow->run();

    if (trim($shallow->getOutput()) === 'true') {
        $this->markTestSkipped('Shallow CI checkout cannot resolve historical commit ids; run the ledger audit on a full clone.');
    }

    $missing = [];

    foreach (array_unique(array_column($rows, 'verified_commit')) as $commit) {
        $process = new Process(
            ['git', 'cat-file', '-e', $commit.'^{commit}'],
            $root,
            ['GIT_OPTIONAL_LOCKS' => '0'],
        );
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            $missing[] = $commit;
        }
    }

    expect($missing)->toBeEmpty();
});

it('closes every exception ledger row as proven or removed', function (): void {
    $root = dirname(__DIR__, 3);
    $rows = readRefactorLedgerCsv($root.'/tasks/feature-refactor-exception-ledger.csv');

    $open = array_values(array_filter(
        $rows,
        static fn (array $row): bool => in_array($row['current_status'], ['IN_TREE', 'KEEP_PENDING_PROOF'], true)
            || in_array($row['final_status'], ['IN_TREE', 'KEEP_PENDING_PROOF'], true),
    ));

    expect($open)->toBeEmpty();

    $unclassified = array_values(array_filter(
        $rows,
        static fn (array $row): bool => ! in_array($row['current_status'], ['PROVEN', 'REMOVED'], true)
            || ! in_array($row['final_status'], ['PROVEN', 'REMOVED'], true),
    ));

    expect($unclassified)->toBeEmpty();

    $incompleteEvidence = array_values(array_filter(
        $rows,
        static fn (array $row): bool => $row['current_status'] === 'PROVEN'
            && (! str_contains($row['final_evidence'], 'callsite ')
                || ! str_contains($row['final_evidence'], 'composer architecture:test')
                || preg_match('/verified at [0-9a-f]{7,40}/', $row['final_evidence']) !== 1),
    ));

    expect($incompleteEvidence)->toBeEmpty();

    $readAdapter = collect($rows)->firstWhere('id', 'RA-11');

    expect($readAdapter)->not->toBeNull()
        ->and($readAdapter['current_status'])->toBe('REMOVED')
        ->and($readAdapter['final_status'])->toBe('REMOVED')
        ->and($readAdapter['final_evidence'])->toContain('9d71d62f', '97-line', 'read_adapters');
});

it('matches the current architecture write-exception inventory', function (): void {
    $root = dirname(__DIR__, 3);
    $rows = readRefactorLedgerCsv($root.'/tasks/feature-refactor-exception-ledger.csv');

    $provenWrites = array_values(array_filter(
        $rows,
        static fn (array $row): bool => $row['category'] === 'PACKAGE_OWNED_WRITE'
            && $row['final_status'] === 'PROVEN',
    ));

    $architecture = json_decode(
        file_get_contents($root.'/tools/architecture.json'),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );

    $writeExceptions = array_values(array_filter(
        $architecture['exceptions'],
        static fn (array $exception): bool => ($exception['rule'] ?? null) === 'architecture.writeLocation',
    ));

    expect($provenWrites)->toHaveCount(62)
        ->and($writeExceptions)->toHaveCount(62);
});
