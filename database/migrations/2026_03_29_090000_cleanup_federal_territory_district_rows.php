<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $federalTerritoryStateIds = DB::table('states')
            ->where('country_id', 132)
            ->whereIn('name', [
                'Kuala Lumpur',
                'Putrajaya',
                'Labuan',
                'Wilayah Persekutuan Kuala Lumpur',
                'Wilayah Persekutuan Putrajaya',
                'Wilayah Persekutuan Labuan',
            ])
            ->pluck('id')
            ->all();

        if ($federalTerritoryStateIds === []) {
            return;
        }

        DB::table('subdistricts')
            ->whereIn('state_id', $federalTerritoryStateIds)
            ->whereNotNull('district_id')
            ->update(['district_id' => null]);

        DB::table('addresses')
            ->whereIn('state_id', $federalTerritoryStateIds)
            ->whereNotNull('district_id')
            ->update(['district_id' => null]);

        DB::table('districts')
            ->whereIn('state_id', $federalTerritoryStateIds)
            ->delete();
    }
};
