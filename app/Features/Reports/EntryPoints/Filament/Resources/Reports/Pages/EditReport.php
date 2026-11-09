<?php

declare(strict_types=1);

namespace App\Features\Reports\EntryPoints\Filament\Resources\Reports\Pages;

use App\Features\Reports\Actions\DeleteReportAction;
use App\Features\Reports\Actions\SaveReportAction;
use App\Features\Reports\EntryPoints\Filament\Resources\Reports\ReportResource;
use App\Features\Reports\Models\Report;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditReport extends EditRecord
{
    protected static string $resource = ReportResource::class;

    #[\Override]
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if (! $record instanceof Report) {
            throw new \RuntimeException('Expected Filament record to be a Report instance.');
        }

        return app(SaveReportAction::class)->handle($data, $record);
    }

    #[\Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->using(function (Report $record): bool {
                    app(DeleteReportAction::class)->handle($record);

                    return true;
                }),
        ];
    }
}
