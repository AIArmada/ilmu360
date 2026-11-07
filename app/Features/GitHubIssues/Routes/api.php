<?php

declare(strict_types=1);

use App\Features\GitHubIssues\EntryPoints\Http\GitHubIssueController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:sanctum'])
    ->prefix('api/v1')
    ->name('api.client.')
    ->group(function (): void {
        Route::post('/github-issues', [GitHubIssueController::class, 'store'])
            ->middleware('throttle:github-issues')
            ->name('github-issues.store');
    });
