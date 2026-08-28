<?php

namespace App\Data\Api\User;

use AIArmada\FilamentAuthz\Facades\Authz;
use App\Models\User;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Support\Transformation\TransformationContextFactory;

class CurrentUserData extends Data
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        private readonly array $payload,
    ) {}

    public static function fromModel(User $user): self
    {
        /** @var array<string, mixed> $payload */
        $payload = $user->withoutRelations()->toArray();
        // Keep nullable profile fields stable in the authenticated-user contract.
        // Eloquent omits null attributes from array serialization when they have
        // never been written, but clients should not have to infer their shape.
        $payload += [
            'gender' => null,
            'date_of_birth' => null,
        ];
        $payload['roles'] = Authz::withScope(
            null,
            fn (): array => $user->getRoleNames()->sort()->values()->all(),
            $user,
        );

        return new self(payload: $payload);
    }

    /** @return array<string, mixed> */
    #[\Override]
    public function transform(
        null|TransformationContextFactory|TransformationContext $transformationContext = null,
    ): array {
        return $this->payload;
    }
}
