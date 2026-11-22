<?php

declare(strict_types=1);

use App\Platform\Http\UrlSanitizer;

it('rejects dangerous, obfuscated, and protocol-relative urls', function (?string $url): void {
    expect(UrlSanitizer::sanitize($url))->toBeNull();
})->with([
    'null' => [null],
    'empty' => ['   '],
    'javascript scheme' => ['javascript:alert(1)'],
    'mixed case javascript' => ['JaVaScRiPt:alert(1)'],
    'entity obfuscated javascript' => ['&#106;avascript:alert(1)'],
    'hex entity obfuscated javascript' => ['&#x6A;avascript:alert(1)'],
    'data uri' => ['data:text/html,<script>alert(1)</script>'],
    'vbscript uri' => ['vbscript:msgbox(1)'],
    'protocol relative' => ['//evil.example.com/phish'],
    'embedded whitespace control' => ["java\tscript:alert(1)"],
    'relative without leading slash' => ['majlis/kuala-lumpur'],
]);

it('preserves safe http, https, and root-relative urls', function (string $url, string $expected): void {
    expect(UrlSanitizer::sanitize($url))->toBe($expected);
})->with([
    'https url' => [' https://ilmu360.test/majlis ', 'https://ilmu360.test/majlis'],
    'http url' => ['http://example.com/path?a=1', 'http://example.com/path?a=1'],
    'root relative url' => ['/majlis/kuala-lumpur', '/majlis/kuala-lumpur'],
]);
