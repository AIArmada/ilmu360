<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('contribution_requests')) {
            return;
        }

        if (Schema::hasIndex('contribution_requests', 'contribution_requests_pending_claim_idx')) {
            return;
        }

        Schema::table('contribution_requests', static function (Blueprint $table): void {
            $table->index(['subject_type', 'type', 'status'], 'contribution_requests_pending_claim_idx');
        });
    }
};
