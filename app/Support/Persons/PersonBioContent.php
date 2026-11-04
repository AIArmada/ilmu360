<?php

declare(strict_types=1);

namespace App\Support\Persons;

final class PersonBioContent
{
    /**
     * Normalize any stored person biography shape into a Tiptap document.
     *
     * Legacy biographies are stored as locale maps or locale/text lists,
     * while newer records may already contain a Tiptap document.
     *
     * @return array<string, mixed>
     */
    public static function toRichContent(mixed $bio): array
    {
        if (is_string($bio)) {
            $decoded = json_decode($bio, true);

            if (is_array($decoded)) {
                $bio = $decoded;
            } else {
                return self::plainTextToRichContent($bio);
            }
        }

        if (! is_array($bio)) {
            return self::emptyDocument();
        }

        if (($bio['type'] ?? null) === 'doc') {
            if (! isset($bio['content']) || ! is_array($bio['content'])) {
                $bio['content'] = [];
            }

            return $bio;
        }

        return self::plainTextToRichContent(self::extractText($bio));
    }

    /**
     * @param  array<mixed>  $bio
     */
    private static function extractText(array $bio): string
    {
        $preferredLocales = array_values(array_filter(
            [app()->getLocale(), 'ms', 'en'],
            static fn (mixed $locale): bool => is_string($locale) && mb_trim($locale) !== '',
        ));

        if (array_is_list($bio)) {
            return self::extractTextFromList($bio, $preferredLocales);
        }

        foreach ($preferredLocales as $locale) {
            $text = $bio[$locale] ?? null;

            if (is_string($text) && mb_trim($text) !== '') {
                return $text;
            }
        }

        foreach ($bio as $text) {
            if (is_string($text) && mb_trim($text) !== '') {
                return $text;
            }
        }

        return '';
    }

    /**
     * @param  list<mixed>  $bio
     * @param  list<string>  $preferredLocales
     */
    private static function extractTextFromList(array $bio, array $preferredLocales): string
    {
        $byLocale = [];
        $fallback = [];

        foreach ($bio as $entry) {
            if (is_string($entry)) {
                if (mb_trim($entry) !== '') {
                    $fallback[] = $entry;
                }

                continue;
            }

            if (! is_array($entry)) {
                continue;
            }

            $text = $entry['text'] ?? null;

            if (! is_string($text) || mb_trim($text) === '') {
                continue;
            }

            $locale = $entry['locale'] ?? null;

            if (is_string($locale) && mb_trim($locale) !== '') {
                $byLocale[$locale] = $text;
            } else {
                $fallback[] = $text;
            }
        }

        foreach ($preferredLocales as $locale) {
            if (isset($byLocale[$locale])) {
                return $byLocale[$locale];
            }
        }

        foreach ($byLocale as $text) {
            return $text;
        }

        return implode("\n\n", $fallback);
    }

    /**
     * @return array<string, mixed>
     */
    private static function plainTextToRichContent(string $text): array
    {
        $text = mb_trim($text);

        if ($text === '') {
            return self::emptyDocument();
        }

        $lines = preg_split('/\R/u', $text) ?: [$text];
        $content = [];

        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $content[] = ['type' => 'hardBreak'];
            }

            if ($line !== '') {
                $content[] = [
                    'type' => 'text',
                    'text' => $line,
                ];
            }
        }

        return [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => $content,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function emptyDocument(): array
    {
        return [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [],
                ],
            ],
        ];
    }
}
