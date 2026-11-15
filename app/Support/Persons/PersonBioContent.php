<?php

declare(strict_types=1);

namespace App\Support\Persons;

final class PersonBioContent
{
    /**
     * Return the canonical person biography document for the rich editor.
     *
     * @return array<string, mixed>
     */
    public static function toRichContent(mixed $bio): array
    {
        if (! is_array($bio) || ($bio['type'] ?? null) !== 'doc') {
            return self::emptyDocument();
        }

        if (! isset($bio['content']) || ! is_array($bio['content'])) {
            $bio['content'] = [];
        }

        return $bio;
    }

    /**
     * @return array<string, mixed>
     */
    public static function plainTextToRichContent(string $text): array
    {
        $lines = preg_split('/\R/u', trim($text)) ?: [$text];
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

        if ($content === []) {
            return self::emptyDocument();
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
