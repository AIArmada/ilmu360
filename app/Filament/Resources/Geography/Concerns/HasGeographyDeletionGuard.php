<?php

namespace App\Filament\Resources\Geography\Concerns;

use App\Actions\Location\GetGeographyDeletionBlockReasonAction;
use App\Models\Country;
use App\Models\District;
use App\Models\State;
use App\Models\Subdistrict;
use Filament\Actions\DeleteAction;
use Illuminate\Database\Eloquent\Model;

trait HasGeographyDeletionGuard
{
    public static function getDeletionBlockedReason(Model $record): ?string
    {
        if (! $record instanceof Country && ! $record instanceof State && ! $record instanceof District && ! $record instanceof Subdistrict) {
            return null;
        }

        return app(GetGeographyDeletionBlockReasonAction::class)->handle($record);
    }

    public static function makeDeleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->disabled(fn (Model $record): bool => filled(static::getDeletionBlockedReason($record)))
            ->tooltip(fn (Model $record): ?string => static::getDeletionBlockedReason($record));
    }
}
