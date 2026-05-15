<?php

declare(strict_types=1);

use Illuminate\Support\Str;

function normalizeRestoredDocContent(string $contents): string
{
    return rtrim(str_replace("\r\n", "\n", $contents), "\n");
}

dataset('active current-brand docs', [
    'executive summary' => 'docs/repo-analysis/executive-1-page.md',
    'founder pitch english' => 'docs/repo-analysis/founder-pitch-1-page.md',
    'founder pitch malay' => 'docs/repo-analysis/founder-pitch-1-page-ms.md',
    'review and enhancement plan' => 'docs/ilmu360_review_and_enhancement_plan.md',
]);

dataset('canonical lowercase docs', [
    'api mcp filament crud comparison json' => 'docs/ilmu360_api_mcp_filament_crud_comparison.json',
    'api mcp filament crud comparison markdown' => 'docs/ilmu360_api_mcp_filament_crud_comparison.md',
    'event domain understanding' => 'docs/ilmu360_event_domain_understanding.md',
    'mcp admin agent guide' => 'docs/ilmu360_mcp_admin_agent_guide.md',
    'mcp csv json creation guide' => 'docs/ilmu360_mcp_event_csv_json_creation_guide.md',
    'mcp guide' => 'docs/ilmu360_mcp_guide.md',
    'mcp member agent guide' => 'docs/ilmu360_mcp_member_agent_guide.md',
    'mcp tool examples' => 'docs/ilmu360_mcp_tool_examples.md',
    'mobile api reference' => 'docs/ilmu360_mobile_api_reference.md',
    'mvp status' => 'docs/ilmu360_mvp_status.md',
    'review and enhancement plan' => 'docs/ilmu360_review_and_enhancement_plan.md',
    'technical documentation' => 'docs/ilmu360_technical_documentation.md',
    'visitor guide' => 'docs/ilmu360_visitor_guide.md',
]);

it('does not keep legacy-prefixed documentation duplicates', function (): void {
    $docsPath = dirname(__DIR__, 2).'/docs';

    $legacyMatches = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($docsPath, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $item) {
        if (! $item instanceof SplFileInfo || ! $item->isFile()) {
            continue;
        }

        $relativePath = str_replace($docsPath.'/', '', $item->getPathname());

        if (str_starts_with($relativePath, 'trash/')) {
            continue;
        }

        $basename = $item->getBasename();

        if (! str_starts_with($basename, 'MAJLISILMU')
            && ! str_starts_with($basename, 'majlisilmu')
            && ! str_starts_with($basename, 'ILMU360')) {
            continue;
        }

        $legacyMatches[] = $relativePath;
    }

    expect($legacyMatches)->toBeEmpty();
});

it('keeps the canonical lowercase documentation set present', function (string $relativePath): void {
    expect(file_exists(dirname(__DIR__, 2).'/'.$relativePath))->toBeTrue();
})->with('canonical lowercase docs');

it('keeps restored trash docs matched to live rebranded counterparts', function (): void {
    $workspaceRoot = dirname(__DIR__, 2);
    $trashFiles = collect(glob($workspaceRoot.'/docs/trash/ILMU360_*') ?: [])
        ->filter(static fn (string $path): bool => is_file($path))
        ->map(static fn (string $path): string => basename($path))
        ->filter(static fn (string $basename): bool => preg_match('/\.(md|json)$/', $basename) === 1)
        ->reject(static fn (string $basename): bool => preg_match('/ \d{2}-\d{2}-\d{2}-\d+\.(md|json)$/', $basename) === 1)
        ->unique()
        ->sort()
        ->values();

    expect($trashFiles)->not->toBeEmpty();

    $trashFiles->each(function (string $basename) use ($workspaceRoot): void {
        $trashPath = $workspaceRoot.'/docs/trash/'.$basename;
        $livePath = $workspaceRoot.'/docs/'.Str::of($basename)
            ->replaceFirst('ILMU360_', 'ilmu360_')
            ->lower()
            ->value();

        expect(file_exists($trashPath))->toBeTrue()
            ->and(file_exists($livePath))->toBeTrue()
            ->and(normalizeRestoredDocContent(file_get_contents($trashPath) ?: ''))
            ->toBe(normalizeRestoredDocContent(file_get_contents($livePath) ?: ''));
    });
});

it('keeps active docs free of transition-era brand notes', function (string $relativePath): void {
    $markdown = file_get_contents(dirname(__DIR__, 2).'/'.$relativePath) ?: '';

    expect($markdown)
        ->not->toContain('Brand note: the rebrand')
        ->not->toContain('Nota jenama: rebrand')
        ->not->toContain('Historical snapshot:')
        ->not->toContain('the rebrand is already live')
        ->not->toContain('the rebrand is already complete')
        ->not->toContain('rebrand sudah selesai');
})->with('active current-brand docs');

it('keeps the active brand standard free of legacy-name messaging', function (): void {
    $markdown = file_get_contents(dirname(__DIR__, 2).'/docs/ilmu360-brand-name-standard.md') ?: '';

    expect($markdown)
        ->not->toContain('**Legacy name:**')
        ->not->toContain('MajlisIlmu = legacy name only')
        ->not->toContain('#MajlisIlmu')
        ->not->toContain('During the rebrand transition')
        ->not->toContain('formerly MajlisIlmu')
        ->not->toContain('MajlisIlmu / ilmu360°')
        ->toContain('## 8. Prior-Name Exception Rule')
        ->toContain('## 23. Archive and Provenance Note Rule')
        ->toContain('> **ilmu360° for identity. ilmu360 for function. Ilmu360 for formality.**');
});