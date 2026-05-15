<?php

namespace App\Support\Events;

use App\Enums\ContactCategory;
use App\Enums\SocialMediaPlatform;
use App\Models\Event;
use App\Models\EventSubmission;
use App\Models\User;
use App\Support\SocialMedia\SocialMediaLinkResolver;

final class SubmitterContactPresenter
{
    public static function labelForEvent(Event $event): string
    {
        $parts = self::contactPartsForEvent($event);

        return self::formatLabel($parts['name'], $parts['email'], $parts['phone']);
    }

    public static function whatsappUrlForEvent(Event $event): ?string
    {
        $parts = self::contactPartsForEvent($event);

        return self::whatsappUrlForPhone($parts['phone']);
    }

    /**
     * @return array{name: ?string, email: ?string, phone: ?string}
     */
    private static function contactPartsForEvent(Event $event): array
    {
        $event->loadMissing([
            'submitter',
            'submissions.contacts',
            'submissions.submitter',
        ]);

        $submission = self::latestSubmission($event);

        if ($submission instanceof EventSubmission) {
            return self::contactPartsForSubmission($submission);
        }

        if ($event->submitter instanceof User) {
            return self::contactPartsForUser($event->submitter);
        }

        return [
            'name' => null,
            'email' => null,
            'phone' => null,
        ];
    }

    /**
     * @return array{name: ?string, email: ?string, phone: ?string}
     */
    private static function contactPartsForSubmission(EventSubmission $submission): array
    {
        if ($submission->submitter instanceof User) {
            return self::contactPartsForUser($submission->submitter);
        }

        return [
            'name' => self::filledString($submission->submitter_name),
            'email' => self::submissionContactValue($submission, ContactCategory::Email),
            'phone' => self::submissionContactValue($submission, ContactCategory::Phone)
                ?? self::submissionContactValue($submission, ContactCategory::WhatsApp),
        ];
    }

    /**
     * @return array{name: ?string, email: ?string, phone: ?string}
     */
    private static function contactPartsForUser(User $user): array
    {
        return [
            'name' => self::filledString($user->name),
            'email' => self::filledString($user->email),
            'phone' => self::filledString($user->phone),
        ];
    }

    private static function formatLabel(?string $name, ?string $email, ?string $phone): string
    {
        $parts = array_filter([$name, $email, $phone]);

        return $parts === [] ? '-' : implode(' | ', $parts);
    }

    private static function whatsappUrlForPhone(?string $phone): ?string
    {
        $normalized = SocialMediaLinkResolver::normalize(
            SocialMediaPlatform::WhatsApp->value,
            self::filledString($phone),
            null,
        );

        $identifier = $normalized['username'];

        if (! is_string($identifier) || $identifier === '') {
            return null;
        }

        return SocialMediaLinkResolver::resolveUrl(SocialMediaPlatform::WhatsApp->value, $identifier, null);
    }

    private static function latestSubmission(Event $event): ?EventSubmission
    {
        if ($event->relationLoaded('submissions')) {
            /** @var EventSubmission|null $submission */
            $submission = $event->submissions
                ->sortByDesc(fn (EventSubmission $submission): int => $submission->created_at?->getTimestamp() ?? 0)
                ->first();

            return $submission;
        }

        /** @var EventSubmission|null $submission */
        $submission = $event->submissions()
            ->with(['contacts', 'submitter'])
            ->latest()
            ->first();

        return $submission;
    }

    private static function submissionContactValue(EventSubmission $submission, ContactCategory $category): ?string
    {
        if ($submission->relationLoaded('contacts')) {
            /** @var mixed $value */
            $value = $submission->contacts
                ->firstWhere('category', $category->value)
                ?->value;

            return self::filledString($value);
        }

        $value = $submission->contacts()
            ->where('category', $category->value)
            ->value('value');

        return self::filledString($value);
    }

    private static function filledString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
