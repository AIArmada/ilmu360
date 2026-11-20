<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/**
 * Scratch, editor, and pre-merge backup artifacts must never be tracked.
 *
 * The dated-backup patterns cover both `.pre-20260511` and labelled variants
 * such as `.pre-majlisilmu-merge-20260511`.
 */
it('tracks no scratch or pre-merge artifacts', function (): void {
    $root = dirname(__DIR__, 3);

    $process = new Process(
        ['git', 'ls-files', '-z'],
        $root,
        ['GIT_OPTIONAL_LOCKS' => '0'],
    );
    $process->setTimeout(30);
    $process->run();

    expect($process->isSuccessful())
        ->toBeTrue('Unable to list tracked files with `git ls-files`: '.$process->getErrorOutput());

    $trackedPaths = array_values(array_filter(
        explode("\0", $process->getOutput()),
        static fn (string $path): bool => $path !== '',
    ));

    expect($trackedPaths)
        ->not->toBeEmpty('`git ls-files` returned no tracked files; the artifact check did not run.');

    $scratchPatterns = [
        '/\.tmp$/',
        '/\.bak$/',
        '/\.orig$/',
        '/~$/',
        '/\.pre-[0-9]{8}/',
        '/\.pre-[A-Za-z0-9._-]*\d{8}$/',
    ];

    $offendingPaths = array_values(array_filter(
        $trackedPaths,
        static function (string $path) use ($scratchPatterns): bool {
            if ($path === 'resources/lang/ms.json.tmp') {
                return true;
            }

            return array_any($scratchPatterns, fn ($pattern) => preg_match($pattern, $path) === 1);
        },
    ));

    expect($offendingPaths)->toBe([], sprintf(
        "Tracked scratch artifacts are not allowed:\n- %s\nRemove them with `git rm` before committing.",
        implode("\n- ", $offendingPaths),
    ));
});
