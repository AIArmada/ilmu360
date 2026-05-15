<?php

use App\States\EventStatus\Approved;
use App\States\EventStatus\Cancelled;
use App\States\EventStatus\Draft;
use App\States\EventStatus\NeedsChanges;
use App\States\EventStatus\Pending;
use App\States\EventStatus\Rejected;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $statusMapping = [
            Draft::class => 'draft',
            Pending::class => 'pending',
            Approved::class => 'approved',
            NeedsChanges::class => 'needs_changes',
            Rejected::class => 'rejected',
            Cancelled::class => 'cancelled',
        ];

        foreach ($statusMapping as $legacyValue => $normalizedValue) {
            DB::table('events')
                ->where('status', $legacyValue)
                ->update(['status' => $normalizedValue]);
        }
    }
};
