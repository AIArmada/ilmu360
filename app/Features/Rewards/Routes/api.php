<?php

declare(strict_types=1);

use App\Features\Rewards\EntryPoints\Http\RewardAppealController;
use App\Features\Rewards\EntryPoints\Http\RewardAwardController;
use App\Features\Rewards\EntryPoints\Http\RewardBalanceController;
use App\Features\Rewards\EntryPoints\Http\RewardBenefitController;
use App\Features\Rewards\EntryPoints\Http\RewardEnrollmentController;
use App\Features\Rewards\EntryPoints\Http\RewardInsightController;
use App\Features\Rewards\EntryPoints\Http\RewardPayeeController;
use App\Features\Rewards\EntryPoints\Http\RewardPayoutController;
use App\Features\Rewards\EntryPoints\Http\RewardProgramController;
use Illuminate\Support\Facades\Route;

Route::middleware(['api', 'auth:sanctum'])
    ->prefix('api/v1/rewards')
    ->name('api.rewards.')
    ->group(function (): void {
        Route::get('/programs', [RewardProgramController::class, 'index'])->name('programs.index');
        Route::get('/programs/{program}', [RewardProgramController::class, 'show'])->name('programs.show');
        Route::post('/programs/{program}/enrollments', [RewardEnrollmentController::class, 'store'])->name('enrollments.store');
        Route::delete('/programs/{program}/enrollments', [RewardEnrollmentController::class, 'destroy'])->name('enrollments.destroy');
        Route::post('/programs/{program}/reaccept', [RewardEnrollmentController::class, 'reaccept'])->name('enrollments.reaccept');
        Route::get('/awards', [RewardAwardController::class, 'index'])->name('awards.index');
        Route::get('/awards/{award}', [RewardAwardController::class, 'show'])->name('awards.show');
        Route::get('/balances', [RewardBalanceController::class, 'balances'])->name('balances.index');
        Route::get('/badges', [RewardBalanceController::class, 'badges'])->name('badges.index');
        Route::get('/appeals', [RewardAppealController::class, 'index'])->name('appeals.index');
        Route::post('/awards/{award}/appeals', [RewardAppealController::class, 'store'])->name('appeals.store');
        Route::get('/appeals/{appeal}', [RewardAppealController::class, 'show'])->name('appeals.show');
        Route::get('/awards/{award}/payout', [RewardPayoutController::class, 'show'])->name('payout.show');
        Route::get('/payouts', [RewardPayoutController::class, 'index'])->name('payouts.index');
        Route::get('/payout-methods', [RewardPayeeController::class, 'methods'])->name('payout-methods.index');
        Route::get('/payees', [RewardPayeeController::class, 'index'])->name('payees.index');
        Route::post('/payees', [RewardPayeeController::class, 'store'])->name('payees.store');
        Route::get('/benefits/catalog', [RewardBenefitController::class, 'catalog'])->name('benefits.catalog');
        Route::post('/benefits/redemptions', [RewardBenefitController::class, 'redeem'])->name('benefits.redeem');
        Route::get('/benefits/grants', [RewardBenefitController::class, 'grants'])->name('benefits.grants');
        Route::get('/benefits/grants/{grant}', [RewardBenefitController::class, 'show'])->name('benefits.show');
        Route::post('/benefits/grants/{grant}/consume', [RewardBenefitController::class, 'consume'])->name('benefits.consume');
        Route::post('/benefits/grants/{grant}/cancel', [RewardBenefitController::class, 'cancel'])->name('benefits.cancel');
        Route::get('/impact', [RewardInsightController::class, 'impact'])->name('impact.show');
        Route::get('/cohort', [RewardInsightController::class, 'cohort'])->name('cohort.show');
    });
