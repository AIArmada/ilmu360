<?php

namespace App\Support\Api\Admin\Concerns;

use Illuminate\Database\Eloquent\Model;

trait ProvidesWorkflowResponse
{
    /**
     * @return array{data: array{resource: array<string, mixed>, record: array<string, mixed>}}
     */
    protected function workflowResponse(string $resourceClass, Model $record): array
    {
        return [
            'data' => [
                'resource' => $this->registry->metadata($resourceClass),
                'record' => $this->registry->serializeRecordDetail($resourceClass, $record),
            ],
        ];
    }
}
