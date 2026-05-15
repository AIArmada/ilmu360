<?php

declare(strict_types=1);

use App\Services\ShareTracking\AffiliateRuntimeDataPurger;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(AffiliateRuntimeDataPurger::class)->purge();
    }

    public function down(): void {}
};
