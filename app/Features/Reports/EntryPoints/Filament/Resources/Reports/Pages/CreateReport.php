<?php

declare(strict_types=1);

namespace App\Features\Reports\EntryPoints\Filament\Resources\Reports\Pages;

use AIArmada\CommerceSupport\Support\OwnerContext;
use App\Features\Reports\Actions\SaveReportAction;
use App\Features\Reports\EntryPoints\Filament\Resources\Reports\ReportResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateReport extends CreateRecord
{
    #[\Override]
    protected static string $resource = ReportResource::class;

    public function boot(): void
    {
        OwnerContext::setForRequest(null);
    }

    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        return app(SaveReportAction::class)->handle($data);
    }
}
