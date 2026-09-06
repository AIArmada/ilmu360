<?php

declare(strict_types=1);

namespace App\Filament\Resources\Reports\Pages;

use App\Actions\Reports\SaveReportAction;
use App\Filament\Resources\Reports\ReportResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateReport extends CreateRecord
{
    protected static string $resource = ReportResource::class;

    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        return app(SaveReportAction::class)->handle($data);
    }
}
