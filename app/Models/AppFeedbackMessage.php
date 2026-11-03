<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AppFeedbackMessageFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property-read User|null $author
 */
class AppFeedbackMessage extends Model
{
    /** @use HasFactory<AppFeedbackMessageFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'app_feedback_messages';

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
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
