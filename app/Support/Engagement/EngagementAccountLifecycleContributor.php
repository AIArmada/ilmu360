<?php

declare(strict_types=1);

namespace App\Support\Engagement;

use AIArmada\CommerceSupport\Support\OwnerContext;
use AIArmada\Engagement\Contracts\EngagementManager;
use AIArmada\Engagement\Models\Bookmark;
use AIArmada\Engagement\Models\Follow;
use AIArmada\Engagement\Models\Response;
use App\Models\Event;
use App\Models\User;
use App\Models\Venue;
use App\Shared\Auth\AccountLifecycleContributor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Preserves an account's follow, save and going state across deletion and
 * restoration. Shapes intentionally match the pre-contributor legacy snapshot
 * so the backfill command can migrate existing deleted-user rows.
 */
final readonly class EngagementAccountLifecycleContributor implements AccountLifecycleContributor
{
    public function key(): string
    {
        return 'engagement';
    }

    public function priority(): int
    {
        return 40;
    }

    public function snapshot(string $accountId): array
    {
        return [
            'user_venue' => $this->venueFollows($accountId)
                ->get()
                ->map(fn (Follow $follow): array => [
                    'follower_type' => $follow->follower_type,
                    'follower_id' => $follow->follower_id,
                    'followable_type' => $follow->followable_type,
                    'followable_id' => $follow->followable_id,
                    'status' => $follow->status,
                    'followed_at' => $follow->followed_at?->toIso8601String(),
                    'created_at' => $follow->created_at?->toIso8601String(),
                    'updated_at' => $follow->updated_at?->toIso8601String(),
                ])
                ->all(),
            'event_saves' => $this->eventBookmarks($accountId)
                ->get()
                ->map(fn (Bookmark $bookmark): array => [
                    'bookmarkable_id' => $bookmark->bookmarkable_id,
                    'bookmarker_type' => $bookmark->bookmarker_type,
                    'bookmarker_id' => $bookmark->bookmarker_id,
                    'bookmarked_at' => $bookmark->bookmarked_at?->toIso8601String(),
                    'created_at' => $bookmark->created_at?->toIso8601String(),
                    'updated_at' => $bookmark->updated_at?->toIso8601String(),
                ])
                ->all(),
            'event_attendees' => Response::query()
                ->where('responder_type', $this->userMorphClass())
                ->where('responder_id', $accountId)
                ->where('response_type', 'going')
                ->get()
                ->map(fn (Response $response): array => [
                    'event_id' => $response->respondable_id,
                    'response_type' => $response->response_type,
                    'created_at' => $response->created_at?->toIso8601String(),
                    'updated_at' => $response->updated_at?->toIso8601String(),
                ])
                ->all(),
            'followings' => Follow::query()
                ->where('follower_type', $this->userMorphClass())
                ->where('follower_id', $accountId)
                ->get()
                ->map(fn (Follow $follow): array => [
                    'follower_type' => $follow->follower_type,
                    'follower_id' => $follow->follower_id,
                    'followable_id' => $follow->followable_id,
                    'followable_type' => $follow->followable_type,
                    'status' => $follow->status,
                    'followed_at' => $follow->followed_at?->toISOString(),
                    'unfollowed_at' => $follow->unfollowed_at?->toISOString(),
                    'notification_level' => $follow->notification_level,
                    'source' => $follow->source,
                ])
                ->all(),
        ];
    }

    public function detach(string $accountId): void
    {
        $this->venueFollows($accountId)->delete();
        $this->eventBookmarks($accountId)->delete();

        Response::query()
            ->where('responder_type', $this->userMorphClass())
            ->where('responder_id', $accountId)
            ->where('response_type', 'going')
            ->get()
            ->each(fn (Response $response): ?bool => $response->delete());

        Follow::query()
            ->where('follower_type', $this->userMorphClass())
            ->where('follower_id', $accountId)
            ->delete();
    }

    public function restore(string $accountId, array $snapshot): void
    {
        $user = User::query()->find($accountId);

        if (! $user instanceof User) {
            return;
        }

        foreach ($snapshot['user_venue'] ?? [] as $data) {
            if (! is_array($data) || ! filled($data['followable_id'] ?? null)) {
                continue;
            }

            OwnerContext::withOwner(null, function () use ($data, $user): void {
                Follow::query()->firstOrCreate(
                    [
                        'follower_type' => $data['follower_type'] ?? $user->getMorphClass(),
                        'follower_id' => $data['follower_id'] ?? $user->getKey(),
                        'followable_type' => $data['followable_type'],
                        'followable_id' => $data['followable_id'],
                    ],
                    [
                        'status' => $data['status'] ?? 'active',
                        'followed_at' => $data['followed_at'] ?? now(),
                    ],
                );
            });
        }

        foreach ($snapshot['event_saves'] ?? [] as $data) {
            if (! is_array($data) || ! filled($data['bookmarkable_id'] ?? null)) {
                continue;
            }

            OwnerContext::withOwner(null, function () use ($data, $user): void {
                Bookmark::query()->firstOrCreate(
                    [
                        'bookmarker_type' => $data['bookmarker_type'] ?? $user->getMorphClass(),
                        'bookmarker_id' => $data['bookmarker_id'] ?? $user->getKey(),
                        'bookmarkable_type' => (new Event)->getMorphClass(),
                        'bookmarkable_id' => $data['bookmarkable_id'],
                    ],
                    [
                        'status' => 'active',
                        'bookmarked_at' => $data['bookmarked_at'] ?? now(),
                    ],
                );
            });
        }

        foreach ($snapshot['event_attendees'] ?? [] as $data) {
            if (! is_array($data)) {
                continue;
            }

            OwnerContext::withOwner(null, function () use ($data, $user): void {
                $event = Event::query()->find($data['event_id'] ?? null);

                if ($event instanceof Event) {
                    app(EngagementManager::class)->respond($user, $event, (string) ($data['response_type'] ?? 'going'));
                }
            });
        }

        foreach ($snapshot['followings'] ?? [] as $data) {
            if (! is_array($data)) {
                continue;
            }

            OwnerContext::withOwner(null, function () use ($data, $user): void {
                Follow::query()->firstOrCreate(
                    [
                        'follower_type' => $data['follower_type'] ?? $user->getMorphClass(),
                        'follower_id' => $data['follower_id'] ?? $user->getKey(),
                        'followable_type' => $data['followable_type'],
                        'followable_id' => $data['followable_id'],
                    ],
                    [
                        'status' => $data['status'] ?? 'active',
                        'followed_at' => isset($data['followed_at']) ? CarbonImmutable::parse($data['followed_at']) : CarbonImmutable::now(),
                        'notification_level' => $data['notification_level'] ?? 'all',
                        'source' => $data['source'] ?? 'restore',
                    ],
                );
            });
        }
    }

    public function afterRestore(string $accountId): void {}

    /**
     * @return Builder<Follow>
     */
    private function venueFollows(string $accountId): Builder
    {
        return Follow::query()
            ->where('follower_type', $this->userMorphClass())
            ->where('follower_id', $accountId)
            ->where('followable_type', (new Venue)->getMorphClass());
    }

    /**
     * @return Builder<Bookmark>
     */
    private function eventBookmarks(string $accountId): Builder
    {
        return Bookmark::query()
            ->where('bookmarker_type', $this->userMorphClass())
            ->where('bookmarker_id', $accountId)
            ->where('status', 'active')
            ->where('bookmarkable_type', (new Event)->getMorphClass());
    }

    private function userMorphClass(): string
    {
        return (new User)->getMorphClass();
    }
}
