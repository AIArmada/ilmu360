<?php

declare(strict_types=1);

namespace App\Features\Directory\Actions\Institutions;

use App\Features\Directory\Support\DirectoryModel;
use App\Shared\Directory\Enums\InstitutionNameType;
use Illuminate\Database\Eloquent\Model;

/**
 * Replace an institution's names with the supplied rows.
 */
final readonly class SyncInstitutionNamesAction
{
    /**
     * @param  list<array{full_name?: string, name_type?: string, language_code?: string, is_primary?: bool}>  $names
     */
    public function handle(Model $institution, array $names): void
    {
        DirectoryModel::assert($institution, 'institution');

        $institutionId = (string) $institution->getKey();

        DirectoryModel::query('institution_name')
            ->where('institution_id', $institutionId)
            ->delete();

        $primarySelected = false;

        foreach ($names as $i => $name) {
            $isPrimary = (bool) ($name['is_primary'] ?? $i === 0) && ! $primarySelected;

            if ($isPrimary) {
                $primarySelected = true;
            }

            DirectoryModel::query('institution_name')->create([
                'institution_id' => $institutionId,
                'name_type' => $name['name_type'] ?? InstitutionNameType::Nickname,
                'full_name' => trim((string) ($name['full_name'] ?? '')),
                'language_code' => $name['language_code'] ?? 'ms',
                'is_primary' => $isPrimary,
            ]);
        }

        $institution->unsetRelation('names');
    }
}
