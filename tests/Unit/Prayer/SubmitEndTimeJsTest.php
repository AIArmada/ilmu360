<?php

use Illuminate\Support\Facades\Process;
use Tests\TestCase;

uses(TestCase::class);

it('clears end_time client-side only for custom times, never for prayer labels', function () {
    if (! Process::run('node --version')->successful()) {
        $this->markTestSkipped('Node.js is not available to execute the shipped form script.');
    }

    // Extract the shipped watcher bodies verbatim from the schema source —
    // the test executes these exact bytes, not a copy.
    $schema = (string) file_get_contents(app_path('Livewire/Pages/SubmitEvent/EventSubmissionFormSchema.php'));

    // Flexible-heredoc closings sit bare (`JS` + newline) or trail the call
    // (`JS)`, `JS),`, `JS,`); accept both so matches never span blocks.
    preg_match_all("/<<<'JS'\n(.*?)\n[ \\t]*JS(?=[,);\\s])/s", $schema, $matches);

    $scripts = array_values(array_filter(
        $matches[1],
        static fn (string $js): bool => str_contains($js, "\$set('end_time', null)"),
    ));

    // The custom_time watcher ($state is the start clock) and the end_time
    // watcher ($state is the end clock). Anything else shipping an end-time
    // clear must join the scenario matrix below deliberately.
    expect($scripts)->toHaveCount(2);

    $runner = <<<'NODE'
        const scripts = JSON.parse(process.argv[2]);
        const results = [];

        // Mirrors Filament's afterStateUpdatedJs globals: $state is the
        // updated field, $get reads siblings, $set writes them back.
        const run = (script, state, gets) => {
            const calls = { cleared: null, notified: false };
            const fn = new Function('$state', '$get', '$set', 'FilamentNotification', script);

            fn(
                state,
                (key) => gets[key] ?? null,
                (key, value) => { calls.cleared = [key, value]; },
                class {
                    title() { return this; }
                    warning() { return this; }
                    send() { calls.notified = true; }
                },
            );

            return calls;
        };

        for (const [index, script] of scripts.entries()) {
            // The custom_time watcher reads $state as the start clock; the
            // end_time watcher reads $state as the end clock.
            const stateIsStart = script.includes('const customTime = $state');

            const invalidCustom = stateIsStart
                ? run(script, '14:00', { prayer_time: 'lain_waktu', end_time: '13:00' })
                : run(script, '13:00', { prayer_time: 'lain_waktu', custom_time: '14:00' });

            const invalidPrayer = stateIsStart
                ? run(script, '14:00', { prayer_time: 'selepas_maghrib', end_time: '13:00' })
                : run(script, '13:00', { prayer_time: 'selepas_maghrib', custom_time: '14:00' });

            const validCustom = stateIsStart
                ? run(script, '14:00', { prayer_time: 'lain_waktu', end_time: '15:00' })
                : run(script, '15:00', { prayer_time: 'lain_waktu', custom_time: '14:00' });

            results.push({ index, invalidCustom, invalidPrayer, validCustom });
        }

        console.log(JSON.stringify(results));
        NODE;

    $file = tempnam(sys_get_temp_dir(), 'end-time-js-').'.mjs';
    file_put_contents($file, $runner);

    try {
        // The schema substitutes a quoted validation message at runtime;
        // any valid string literal exercises the same control flow.
        $payload = json_encode(array_map(
            static fn (string $js): string => str_replace('__END_TIME_VALIDATION_MESSAGE__', '"Masa akhir mestilah selepas masa mula."', $js),
            $scripts,
        ));

        $process = Process::run(['node', $file, $payload]);
    } finally {
        @unlink($file);
    }

    expect($process->successful())->toBeTrue($process->errorOutput());

    $results = json_decode($process->output(), true);

    expect($results)->toHaveCount(2);

    foreach ($results as $result) {
        // Custom times with an end before the start still clear + notify.
        expect($result['invalidCustom']['cleared'])->toBe(['end_time', null])
            ->and($result['invalidCustom']['notified'])->toBeTrue()
            // Prayer labels never clear client-side: their start clocks
            // resolve server-side against fresh cache-only data, and the
            // hidden preview snapshot must not destroy user input.
            ->and($result['invalidPrayer']['cleared'])->toBeNull()
            ->and($result['invalidPrayer']['notified'])->toBeFalse()
            // Valid custom ranges are untouched.
            ->and($result['validCustom']['cleared'])->toBeNull()
            ->and($result['validCustom']['notified'])->toBeFalse();
    }
});
