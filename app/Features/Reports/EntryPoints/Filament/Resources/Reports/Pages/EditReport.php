<?php

declare(strict_types=1);

namespace App\Features\Reports\EntryPoints\Filament\Resources\Reports\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Reports\Actions\DeleteReportAction;
use App\Features\Reports\Actions\SaveReportAction;
use App\Features\Reports\EntryPoints\Filament\Resources\Reports\ReportResource;
use App\Features\Reports\EntryPoints\Filament\Resources\Reports\Support\ReportModerationActions;
use App\Features\Reports\Models\Report;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditReport extends EditRecord
{
    #[\Override]
    protected static string $resource = ReportResource::class;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

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
        $redirectAfter = function (Report $record): void {
            $this->redirect(ReportResource::getUrl('edit', ['record' => $record]), navigate: true);
        };

        return [
            ReportModerationActions::triageAction($redirectAfter),
            ReportModerationActions::resolveAction($redirectAfter),
            ReportModerationActions::dismissAction($redirectAfter),
            ReportModerationActions::reopenAction($redirectAfter),
            ReportModerationActions::openEntityAction(),
            DeleteAction::make()
                ->using(function (Report $record): bool {
                    app(DeleteReportAction::class)->handle($record);

                    ReportResource::flushNavigationBadgeCache();

                    return true;
                }),
        ];
    }
}
