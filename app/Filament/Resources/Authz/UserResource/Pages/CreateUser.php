<?php

declare(strict_types=1);

namespace App\Filament\Resources\Authz\UserResource\Pages;

use App\Filament\Resources\Authz\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    #[\Override]
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        unset($data['roles'], $data['permissions']);

        return $data;
    }

    /**
     * Verification timestamps are not mass assignable. They are applied only
     * after the authorized admin create operation has persisted the user.
     *
     * @param  array<string, mixed>  $data
     */
    #[\Override]
    protected function handleRecordCreation(array $data): Model
    {
        $verificationData = array_intersect_key($data, array_flip([
            'email_verified_at',
            'phone_verified_at',
        ]));
        unset($data['email_verified_at'], $data['phone_verified_at']);

        $record = parent::handleRecordCreation($data);

        if ($record instanceof User && $verificationData !== []) {
            $record->forceFill($verificationData)->save();
        }

        return $record;
    }
}
