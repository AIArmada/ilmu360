<?php

declare(strict_types=1);

namespace App\Features\Feedback\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AppFeedbackMessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * @property string $id
 * @property string $feedback_id
 * @property string|null $author_id
 * @property string $author_role
 * @property string $visibility
 * @property string $body
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read AppFeedback|null $feedback
 * @property-read Model|null $author
 */
class AppFeedbackMessage extends Model
{
    /** @use HasFactory<AppFeedbackMessageFactory> */
    use HasFactory;

    use HasUuids;

    #[\Override]
    protected $table = 'app_feedback_messages';

    #[\Override]
    protected $fillable = [
        'author_role',
        'visibility',
        'body',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<AppFeedback, $this>
     */
    public function feedback(): BelongsTo
    {
        return $this->belongsTo(AppFeedback::class, 'feedback_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo($this->accountModelClass(), 'author_id');
    }

    protected static function newFactory(): AppFeedbackMessageFactory
    {
        return AppFeedbackMessageFactory::new();
    }

    /**
     * @return class-string<Model>
     */
    private function accountModelClass(): string
    {
        $model = config('auth.providers.users.model');

        if (! is_string($model) || ! is_a($model, Model::class, true)) {
            throw new RuntimeException('The users auth provider must reference an Eloquent model.');
        }

        /** @var class-string<Model> $model */
        return $model;
    }
}
