<?php

declare(strict_types=1);

namespace App\Platform\Reporting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RuntimeException;

/**
 * Resolves the feature-owned report model through its stable morph alias so
 * subject models can keep their read-only report relations without importing
 * feature-private classes.
 */
final class ReportModelClass
{
    /**
     * @return class-string<Model>
     */
    public static function resolve(): string
    {
        $model = Relation::getMorphedModel('report');

        if ($model === null || ! is_a($model, Model::class, true)) {
            throw new RuntimeException('The report model must be registered under the [report] morph alias.');
        }

        return $model;
    }
}
