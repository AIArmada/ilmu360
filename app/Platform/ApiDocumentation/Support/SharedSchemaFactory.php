<?php

declare(strict_types=1);

namespace App\Platform\ApiDocumentation\Support;

use Dedoc\Scramble\Support\Generator\Components;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\IntegerType;
use Dedoc\Scramble\Support\Generator\Types\MixedType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;
use Dedoc\Scramble\Support\Generator\Types\Type;

/**
 * Reusable schema fragments shared by owner document contributors.
 */
final class SharedSchemaFactory
{
    public function publicFormFieldContractSchema(): Schema
    {
        return Schema::fromType($this->formFieldType());
    }

    public function publicConditionalRuleSchema(): Schema
    {
        return Schema::fromType($this->conditionalRuleType());
    }

    public function mixedObjectType(): ObjectType
    {
        return (new ObjectType)->additionalProperties(new MixedType);
    }

    public function requestMetaType(): ObjectType
    {
        return (new ObjectType)
            ->addProperty('request_id', new StringType)
            ->setRequired(['request_id']);
    }

    public function searchBucketType(Reference $itemReference): ObjectType
    {
        return (new ObjectType)
            ->addProperty('items', (new ArrayType)->setItems($itemReference))
            ->addProperty('total', new IntegerType)
            ->setRequired(['items', 'total']);
    }

    public function directoryMetaType(): ObjectType
    {
        return (new ObjectType)
            ->addProperty(
                'pagination',
                (new ObjectType)
                    ->addProperty('page', new IntegerType)
                    ->addProperty('per_page', new IntegerType)
                    ->addProperty('total', new IntegerType)
                    ->setRequired(['page', 'per_page', 'total']),
            )
            ->addProperty(
                'cache',
                (new ObjectType)
                    ->addProperty('version', new StringType)
                    ->setRequired(['version']),
            )
            ->addProperty(
                'following',
                (new ObjectType)
                    ->addProperty('total', new IntegerType)
                    ->setRequired(['total']),
            )
            ->addProperty('request_id', new StringType)
            ->setRequired(['pagination', 'cache', 'request_id']);
    }

    public function directoryFilterOptionType(): ObjectType
    {
        return (new ObjectType)
            ->addProperty('value', new StringType)
            ->addProperty('label', new StringType)
            ->setRequired(['value', 'label']);
    }

    /**
     * @param  array<string, Type|null>  $extraProperties
     */
    public function standardFormContractType(Components $components, array $extraProperties = [], bool $includeDefaults = true): ObjectType
    {
        $object = (new ObjectType)
            ->addProperty('flow', new StringType)
            ->addProperty('method', new StringType)
            ->addProperty('endpoint', new StringType)
            ->addProperty('auth_required', new BooleanType)
            ->addProperty('fields', (new ArrayType)->setItems($components->getSchemaReference('PublicFormFieldContract')))
            ->addProperty('conditional_rules', (new ArrayType)->setItems($components->getSchemaReference('PublicConditionalRule')));

        if ($includeDefaults) {
            $object->addProperty('defaults', $this->mixedObjectType());
        }

        foreach ($extraProperties as $name => $type) {
            if ($type !== null) {
                $object->addProperty($name, $type);
            }
        }

        $required = ['flow', 'method', 'endpoint', 'auth_required', 'fields', 'conditional_rules'];

        if ($includeDefaults) {
            $required[] = 'defaults';
        }

        return $object->setRequired(array_merge($required, array_keys(array_filter($extraProperties, static fn (?Type $type): bool => $type instanceof Type))));
    }

    public function formFieldType(): ObjectType
    {
        return (new ObjectType)
            ->addProperty('name', new StringType)
            ->addProperty('type', new StringType)
            ->addProperty('required', new BooleanType)
            ->addProperty('label', (new StringType)->nullable(true))
            ->addProperty('description', (new StringType)->nullable(true))
            ->addProperty('default', new MixedType)
            ->addProperty('allowed_values', (new ArrayType)->setItems(new MixedType)->nullable(true))
            ->addProperty('max_length', (new IntegerType)->nullable(true))
            ->addProperty('min_length', (new IntegerType)->nullable(true))
            ->addProperty('multiple', (new BooleanType)->nullable(true))
            ->addProperty('catalog', (new StringType)->nullable(true))
            ->addProperty('accept', (new ArrayType)->setItems(new StringType)->nullable(true))
            ->additionalProperties(new MixedType)
            ->setRequired(['name', 'type', 'required']);
    }

    public function conditionalRuleType(): ObjectType
    {
        return (new ObjectType)
            ->addProperty('field', new StringType)
            ->additionalProperties(new MixedType)
            ->setRequired(['field']);
    }
}
