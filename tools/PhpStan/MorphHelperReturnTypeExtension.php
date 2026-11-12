<?php

declare(strict_types=1);

namespace Tools\PhpStan;

use App\Features\Directory\Support\DirectoryModel;
use App\Features\Events\Support\EventModel;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\Generic\GenericClassStringType;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * Types the morph-alias helper facades used by feature actions.
 *
 * EventModel/DirectoryModel intentionally expose root models through stable
 * aliases without importing them. This extension recovers the literal alias
 * at each call site so Larastan can resolve scopes, relations and properties.
 */
final class MorphHelperReturnTypeExtension implements DynamicStaticMethodReturnTypeExtension
{
    /**
     * @var array<class-string, array<string, string>>
     */
    private const ALIAS_GETTERS = [
        EventModel::class => [
            'event' => 'event',
            'series' => 'series',
            'occurrence' => 'event_occurrence',
            'session' => 'event_session',
            'submission' => 'event_submission',
            'keyPerson' => 'event_key_person',
            'location' => 'event_location',
            'registration' => 'registration',
            'institution' => 'institution',
            'space' => 'space',
            'language' => 'language',
            'user' => 'user',
        ],
        DirectoryModel::class => [
            'institution' => 'institution',
            'institutionName' => 'institution_name',
            'importExclusion' => 'institution_import_exclusion',
            'donationChannel' => 'donation_channel',
            'person' => 'person',
            'personName' => 'person_name',
            'affiliation' => 'affiliation',
            'reference' => 'reference',
            'venue' => 'venue',
            'space' => 'space',
        ],
    ];

    /**
     * @param  class-string  $helperClass
     */
    public function __construct(private readonly string $helperClass) {}

    public function getClass(): string
    {
        return $this->helperClass;
    }

    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        $method = $methodReflection->getName();

        return in_array($method, ['query', 'new', 'assert', 'referenceQuery'], true)
            || isset(self::ALIAS_GETTERS[$this->helperClass][$method]);
    }

    public function getTypeFromStaticMethodCall(
        MethodReflection $methodReflection,
        StaticCall $methodCall,
        Scope $scope,
    ): ?Type {
        $method = $methodReflection->getName();
        $arguments = $methodCall->getArgs();

        if ($method === 'query') {
            $modelClass = $this->literalAliasClass($arguments[0]->value ?? null, $scope);

            return $modelClass === null ? null : new GenericObjectType(
                \Illuminate\Database\Eloquent\Builder::class,
                [new ObjectType($modelClass)],
            );
        }

        if ($method === 'new') {
            $modelClass = $this->literalAliasClass($arguments[0]->value ?? null, $scope);

            return $modelClass === null ? null : new ObjectType($modelClass);
        }

        if ($method === 'assert') {
            $modelClass = $this->literalAliasClass($arguments[1]->value ?? null, $scope);

            return $modelClass === null ? null : new ObjectType($modelClass);
        }

        if ($method === 'referenceQuery' && $this->helperClass === DirectoryModel::class) {
            return new GenericObjectType(
                \Illuminate\Database\Eloquent\Builder::class,
                [new ObjectType(MorphModelResolverReturnTypeExtension::MORPH_MODELS['reference'])],
            );
        }

        $alias = self::ALIAS_GETTERS[$this->helperClass][$method] ?? null;
        $modelClass = $alias === null
            ? null
            : MorphModelResolverReturnTypeExtension::MORPH_MODELS[$alias];

        return $modelClass === null ? null : new GenericClassStringType(new ObjectType($modelClass));
    }

    private function literalAliasClass(?Expr $aliasArgument, Scope $scope): ?string
    {
        if ($aliasArgument === null) {
            return null;
        }

        $constantStrings = $scope->getType($aliasArgument)->getConstantStrings();

        if (count($constantStrings) !== 1) {
            return null;
        }

        return MorphModelResolverReturnTypeExtension::MORPH_MODELS[$constantStrings[0]->getValue()] ?? null;
    }
}
