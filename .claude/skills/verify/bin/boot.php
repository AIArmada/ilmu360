<?php

declare(strict_types=1);

/*
 * Browser probes inherit Tests\TestCase, which calls withoutVite().
 * Rebind a real Vite instance so screenshots use public/build instead of
 * rendering unstyled. The hot file is deliberately pointed at a missing
 * path: a stale public/hot points the test server at a dev server it cannot
 * reach, which is the usual cause of blank styling.
 */
$vite = new Illuminate\Foundation\Vite;
$vite->useHotFile(storage_path('framework/vite-hot-disabled'));

app()->instance(Illuminate\Foundation\Vite::class, $vite);
Illuminate\Support\Facades\Vite::clearResolvedInstance();
