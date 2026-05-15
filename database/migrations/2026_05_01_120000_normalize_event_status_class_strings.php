<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $legacyStatuses = DB::table('events')
            ->select('status')
            ->whereNotNull('status')
            ->where('status', 'like', '%App\\States\\EventStatus\\%')
            ->distinct()
            ->pluck('status')
            ->filter(static fn (mixed $status): bool => is_string($status) && $status !== '');

        foreach ($legacyStatuses as $legacyStatus) {
            $normalizedStatus = $this->normalizeStatusValue($legacyStatus);

            if ($normalizedStatus === null) {
                continue;
            }

            DB::table('events')
                ->where('status', $legacyStatus)
                ->update(['status' => $normalizedStatus]);
        }
    }

    private function normalizeStatusValue(string $legacyStatus): ?string
    {
        $trimmedStatus = trim($legacyStatus, ' \\');

        if (! str_contains($trimmedStatus, 'App\\States\\EventStatus\\')) {
            return null;
        }

        $classBaseName = Str::afterLast($trimmedStatus, '\\');
        $snakeCase = Str::snake($classBaseName);

        return in_array($snakeCase, [
            'draft',
            'pending',
            'approved',
            'needs_changes',
            'rejected',
            'cancelled',
        ], true) ? $snakeCase : null;
    }
};
