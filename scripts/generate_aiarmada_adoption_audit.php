<?php

declare(strict_types=1);

const AUDIT_ROOT = __DIR__.'/..';
const REPORT_PATH = AUDIT_ROOT.'/docs/aiarmada-adoption/aiarmada-360-audit.html';
const GRAPHIFY_REPORT_PATH = AUDIT_ROOT.'/graphify-out/GRAPH_REPORT.md';
const COMMERCE_PACKAGES_PATH = '/Users/Saiffil/Herd/commerce/packages';

chdir(AUDIT_ROOT);

main();

function main(): void
{
    $directPackages = directAiarmadaRequirements();
    $installedPackages = installedAiarmadaPackages();
    $availablePackages = availableCommercePackages();
    $routeInventory = routeInventory();
    $namespaceMap = packageNamespaceMap();
    $layerHits = namespaceHitsByLayer($namespaceMap);
    $wrapperModels = wrapperModels();
    $traitAdoptions = traitAdoptions();
    $configFiles = packageConfigFiles();
    $graphify = graphifySummary();
    $layerFileCount = totalCoreCodeFiles();
    $assessments = packageAssessments();
    $gapRegister = gapRegister();
    $opportunities = opportunityPackages($availablePackages, array_keys($directPackages));

    $packages = [];

    foreach ($installedPackages as $installedPackage) {
        $name = (string) ($installedPackage['name'] ?? '');

        if ($name === '' || ! isset($directPackages[$name])) {
            continue;
        }

        $routeGroupKey = routeGroupKeyForPackage($name);
        $layerData = $layerHits[$name] ?? [
            'total_files' => 0,
            'total_matches' => 0,
            'layers' => [],
        ];
        $assessment = $assessments[$name] ?? defaultAssessment($name);

        $packages[] = [
            'name' => $name,
            'namespace' => $namespaceMap[$name] ?? null,
            'description' => $installedPackage['description'] ?? null,
            'version' => $installedPackage['version'] ?? null,
            'direct_dependency' => (bool) ($installedPackage['direct-dependency'] ?? false),
            'route_count' => $routeInventory['aiarmada_routes_by_group'][$routeGroupKey]['count'] ?? 0,
            'route_samples' => $routeInventory['aiarmada_routes_by_group'][$routeGroupKey]['samples'] ?? [],
            'reference_summary' => $layerData,
            'classification' => $assessment['classification'],
            'gap_reason' => $assessment['gap_reason'],
            'priority' => $assessment['priority'],
            'native_surface' => $assessment['native_surface'],
            'app_owned_surface' => $assessment['app_owned_surface'],
            'summary' => $assessment['summary'],
            'evidence' => $assessment['evidence'],
            'next_steps' => $assessment['next_steps'],
        ];
    }

    usort(
        $packages,
        static function (array $left, array $right): int {
            $priorityOrder = [
                'Critical' => 0,
                'High' => 1,
                'Medium' => 2,
                'Low' => 3,
            ];

            $leftPriority = $priorityOrder[$left['priority']] ?? 99;
            $rightPriority = $priorityOrder[$right['priority']] ?? 99;

            if ($leftPriority !== $rightPriority) {
                return $leftPriority <=> $rightPriority;
            }

            return strcmp((string) $left['name'], (string) $right['name']);
        }
    );

    $payload = [
        'generated_at' => date(DATE_ATOM),
        'objective' => 'Audit app-built surfaces versus natively adopted AIArmada package surfaces across ilmu360.',
        'graphify' => $graphify,
        'direct_aiarmada_packages' => array_values(array_keys($directPackages)),
        'installed_aiarmada_packages' => $installedPackages,
        'available_commerce_packages' => $availablePackages,
        'route_inventory' => $routeInventory,
        'layer_file_count' => $layerFileCount,
        'wrapper_models' => $wrapperModels,
        'trait_adoptions' => $traitAdoptions,
        'package_config_files' => $configFiles,
        'packages' => $packages,
        'gap_register' => $gapRegister,
        'opportunity_packages' => $opportunities,
        'source_documents' => [
            'docs/aiarmada-adoption/status.md',
            'docs/aiarmada-adoption/package-inventory.md',
            'docs/aiarmada-adoption/domain-mapping.md',
            'docs/aiarmada-adoption/architecture-decisions.md',
            'graphify-out/GRAPH_REPORT.md',
        ],
    ];

    file_put_contents(REPORT_PATH, renderHtml($payload));

    fwrite(STDOUT, "Wrote ".REPORT_PATH.PHP_EOL);
}

/**
 * @return array<string, string>
 */
function directAiarmadaRequirements(): array
{
    /** @var array<string, mixed> $composer */
    $composer = json_decode(file_get_contents(AUDIT_ROOT.'/composer.json') ?: '{}', true) ?: [];
    $require = $composer['require'] ?? [];
    $packages = [];

    foreach ($require as $name => $constraint) {
        if (! is_string($name) || ! str_starts_with($name, 'aiarmada/')) {
            continue;
        }

        $packages[$name] = (string) $constraint;
    }

    ksort($packages);

    return $packages;
}

/**
 * @return list<array<string, mixed>>
 */
function installedAiarmadaPackages(): array
{
    $output = runCommand("composer show 'aiarmada/*' --format=json");
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($output, true) ?: [];
    $installed = $decoded['installed'] ?? [];

    if (! is_array($installed)) {
        return [];
    }

    usort(
        $installed,
        static fn (array $left, array $right): int => strcmp((string) ($left['name'] ?? ''), (string) ($right['name'] ?? ''))
    );

    return array_values(array_filter($installed, static fn (mixed $package): bool => is_array($package)));
}

/**
 * @return list<array{name: string, path: string, description: string|null}>
 */
function availableCommercePackages(): array
{
    $packages = [];

    foreach (glob(COMMERCE_PACKAGES_PATH.'/*/composer.json') ?: [] as $composerPath) {
        /** @var array<string, mixed> $composer */
        $composer = json_decode(file_get_contents($composerPath) ?: '{}', true) ?: [];
        $name = $composer['name'] ?? null;

        if (! is_string($name) || ! str_starts_with($name, 'aiarmada/')) {
            continue;
        }

        $packages[] = [
            'name' => $name,
            'path' => dirname($composerPath),
            'description' => is_string($composer['description'] ?? null) ? $composer['description'] : null,
        ];
    }

    usort($packages, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));

    return $packages;
}

/**
 * @return array<string, string>
 */
function packageNamespaceMap(): array
{
    return [
        'aiarmada/addressing' => 'AIArmada\\Addressing\\',
        'aiarmada/affiliates' => 'AIArmada\\Affiliates\\',
        'aiarmada/authz' => 'AIArmada\\Authz\\',
        'aiarmada/commerce-support' => 'AIArmada\\CommerceSupport\\',
        'aiarmada/communications' => 'AIArmada\\Communications\\',
        'aiarmada/contacting' => 'AIArmada\\Contacting\\',
        'aiarmada/engagement' => 'AIArmada\\Engagement\\',
        'aiarmada/events' => 'AIArmada\\Events\\',
        'aiarmada/filament-addressing' => 'AIArmada\\FilamentAddressing\\',
        'aiarmada/filament-authz' => 'AIArmada\\FilamentAuthz\\',
        'aiarmada/filament-communications' => 'AIArmada\\Filament\\Communications\\',
        'aiarmada/filament-contacting' => 'AIArmada\\FilamentContacting\\',
        'aiarmada/filament-engagement' => 'AIArmada\\FilamentEngagement\\',
        'aiarmada/filament-events' => 'AIArmada\\FilamentEvents\\',
        'aiarmada/filament-inventory' => 'AIArmada\\FilamentInventory\\',
        'aiarmada/filament-seating' => 'AIArmada\\FilamentSeating\\',
        'aiarmada/filament-signals' => 'AIArmada\\FilamentSignals\\',
        'aiarmada/filament-ticketing' => 'AIArmada\\FilamentTicketing\\',
        'aiarmada/inventory' => 'AIArmada\\Inventory\\',
        'aiarmada/membership' => 'AIArmada\\Membership\\',
        'aiarmada/moderation' => 'AIArmada\\Moderation\\',
        'aiarmada/references' => 'AIArmada\\References\\',
        'aiarmada/seating' => 'AIArmada\\Seating\\',
        'aiarmada/signals' => 'AIArmada\\Signals\\',
        'aiarmada/ticketing' => 'AIArmada\\Ticketing\\',
    ];
}

/**
 * @return array<string, mixed>
 */
function routeInventory(): array
{
    /** @var list<array<string, mixed>> $routes */
    $routes = json_decode(runCommand('php artisan route:list --json'), true) ?: [];

    $ownerCounts = [];
    $domainCounts = [];
    $aiarmadaRoutesByGroup = [];
    $appCount = 0;
    $aiarmadaCount = 0;

    foreach ($routes as $route) {
        $action = is_string($route['action'] ?? null) ? (string) $route['action'] : 'Closure';
        $class = str_contains($action, '@') ? explode('@', $action)[0] : $action;
        $owner = routeOwner($class);
        $ownerCounts[$owner] = ($ownerCounts[$owner] ?? 0) + 1;

        $domain = is_string($route['domain'] ?? null) && $route['domain'] !== ''
            ? (string) $route['domain']
            : '(none)';
        $domainCounts[$domain] = ($domainCounts[$domain] ?? 0) + 1;

        if ($owner === 'App') {
            $appCount++;
        }

        if (str_starts_with($class, 'AIArmada\\')) {
            $aiarmadaCount++;
            $group = aiarmadaRouteGroup($class);
            $aiarmadaRoutesByGroup[$group] ??= [
                'count' => 0,
                'samples' => [],
            ];
            $aiarmadaRoutesByGroup[$group]['count']++;

            if (count($aiarmadaRoutesByGroup[$group]['samples']) < 8) {
                $aiarmadaRoutesByGroup[$group]['samples'][] = [
                    'method' => (string) ($route['method'] ?? ''),
                    'uri' => (string) ($route['uri'] ?? ''),
                    'name' => is_string($route['name'] ?? null) ? (string) $route['name'] : null,
                    'action' => $action,
                ];
            }
        }
    }

    arsort($ownerCounts);
    arsort($domainCounts);
    uasort(
        $aiarmadaRoutesByGroup,
        static fn (array $left, array $right): int => ($right['count'] <=> $left['count'])
    );

    return [
        'total_routes' => count($routes),
        'app_routes' => $appCount,
        'aiarmada_routes' => $aiarmadaCount,
        'owner_counts' => $ownerCounts,
        'domain_counts' => $domainCounts,
        'aiarmada_routes_by_group' => $aiarmadaRoutesByGroup,
    ];
}

function routeOwner(string $class): string
{
    return match (true) {
        str_starts_with($class, 'App\\') => 'App',
        str_starts_with($class, 'AIArmada\\') => 'AIArmada.'.aiarmadaRouteGroup($class),
        str_starts_with($class, 'Laravel\\Fortify\\') => 'Laravel.Fortify',
        str_starts_with($class, 'Laravel\\Passport\\') => 'Laravel.Passport',
        str_starts_with($class, 'Laravel\\Mcp\\') => 'Laravel.Mcp',
        str_starts_with($class, 'Livewire\\') => 'Livewire',
        str_starts_with($class, 'Filament\\') => 'Filament',
        str_starts_with($class, 'Clockwork\\') => 'Clockwork',
        str_starts_with($class, 'Nnjeim\\World\\') => 'World',
        str_starts_with($class, 'Illuminate\\') => 'Illuminate',
        str_starts_with($class, 'Flux\\') => 'Flux',
        $class === 'Closure' => 'Closure',
        default => $class,
    };
}

function aiarmadaRouteGroup(string $class): string
{
    $segments = explode('\\', $class);

    return $segments[1] ?? 'Unknown';
}

/**
 * @return array<string, array{total_files: int, total_matches: int, layers: array<string, array{count: int, examples: list<string>}>}>
 */
function namespaceHitsByLayer(array $namespaceMap): array
{
    $layers = [
        'models' => 'app/Models',
        'controllers' => 'app/Http/Controllers',
        'actions' => 'app/Actions',
        'services' => 'app/Services',
        'support' => 'app/Support',
        'livewire' => 'app/Livewire',
        'filament' => 'app/Filament',
        'providers' => 'app/Providers',
        'mcp' => 'app/Mcp',
        'config' => 'config',
        'routes' => 'routes',
        'tests' => 'tests',
    ];

    $results = [];

    foreach ($namespaceMap as $package => $namespace) {
        $results[$package] = [
            'total_files' => 0,
            'total_matches' => 0,
            'layers' => [],
        ];

        foreach ($layers as $layerName => $directory) {
            if (! is_dir(AUDIT_ROOT.'/'.$directory)) {
                continue;
            }

            $count = 0;
            $matches = 0;
            $examples = [];

            foreach (phpFilesIn($directory) as $filePath) {
                $contents = file_get_contents(AUDIT_ROOT.'/'.$filePath) ?: '';
                $fileMatches = substr_count($contents, $namespace);

                if ($fileMatches === 0) {
                    continue;
                }

                $count++;
                $matches += $fileMatches;

                if (count($examples) < 4) {
                    $examples[] = $filePath;
                }
            }

            if ($count === 0) {
                continue;
            }

            $results[$package]['total_files'] += $count;
            $results[$package]['total_matches'] += $matches;
            $results[$package]['layers'][$layerName] = [
                'count' => $count,
                'examples' => $examples,
            ];
        }
    }

    return $results;
}

/**
 * @return list<array{file: string, base_class: string}>
 */
function wrapperModels(): array
{
    $wrappers = [];

    foreach (phpFilesIn('app/Models') as $filePath) {
        $contents = file_get_contents(AUDIT_ROOT.'/'.$filePath) ?: '';
        $aliases = useAliases($contents);

        if (! preg_match('/class\s+\w+\s+extends\s+([^\s{]+)/', $contents, $match)) {
            continue;
        }

        $parent = trim($match[1]);
        $resolved = $aliases[$parent] ?? $parent;

        if (! str_contains($resolved, 'AIArmada\\')) {
            continue;
        }

        $wrappers[] = [
            'file' => $filePath,
            'base_class' => $resolved,
        ];
    }

    usort($wrappers, static fn (array $left, array $right): int => strcmp($left['file'], $right['file']));

    return $wrappers;
}

/**
 * @return list<array{file: string, trait: string}>
 */
function traitAdoptions(): array
{
    $adoptions = [];

    foreach (phpFilesIn('app/Models') as $filePath) {
        $contents = file_get_contents(AUDIT_ROOT.'/'.$filePath) ?: '';

        preg_match_all('/use\s+([^;]+);/', $contents, $matches);

        foreach ($matches[1] as $useStatement) {
            $useStatement = trim((string) $useStatement);

            if (
                str_contains($useStatement, 'AIArmada\\')
                && (str_contains($useStatement, '\\Traits\\') || str_contains($useStatement, '\\Concerns\\'))
            ) {
                $adoptions[] = [
                    'file' => $filePath,
                    'trait' => $useStatement,
                ];
            }
        }
    }

    usort($adoptions, static fn (array $left, array $right): int => strcmp($left['file'].$left['trait'], $right['file'].$right['trait']));

    return $adoptions;
}

/**
 * @return list<array{file: string, lines: int}>
 */
function packageConfigFiles(): array
{
    $configNames = [
        'addressing.php',
        'authz.php',
        'communications.php',
        'contacting.php',
        'engagement.php',
        'events.php',
        'filament-authz.php',
        'filament-inventory.php',
        'filament-seating.php',
        'filament-signals.php',
        'filament-ticketing.php',
        'inventory.php',
        'membership.php',
        'moderation.php',
        'references.php',
        'seating.php',
        'signals.php',
        'ticketing.php',
    ];

    $files = [];

    foreach ($configNames as $configName) {
        $path = AUDIT_ROOT.'/config/'.$configName;

        if (! is_file($path)) {
            continue;
        }

        $files[] = [
            'file' => 'config/'.$configName,
            'lines' => count(file($path) ?: []),
        ];
    }

    usort($files, static fn (array $left, array $right): int => $right['lines'] <=> $left['lines']);

    return $files;
}

/**
 * @return array<string, mixed>
 */
function graphifySummary(): array
{
    if (! is_file(GRAPHIFY_REPORT_PATH)) {
        return [
            'present' => false,
            'note' => 'Graphify report not found.',
        ];
    }

    $lines = file(GRAPHIFY_REPORT_PATH, FILE_IGNORE_NEW_LINES) ?: [];
    $summary = [
        'present' => true,
        'report_path' => 'graphify-out/GRAPH_REPORT.md',
        'header' => null,
        'corpus_check' => [],
        'summary' => [],
        'community_hubs' => [],
        'tooling_note' => 'Graphify is installed and used as analysis tooling for this audit; it is not an app runtime package dependency.',
    ];

    foreach ($lines as $index => $line) {
        if ($index === 0) {
            $summary['header'] = trim($line);
        }

        if (trim($line) === '## Corpus Check') {
            foreach (array_slice($lines, $index + 1, 3) as $candidate) {
                if (str_starts_with($candidate, '- ')) {
                    $summary['corpus_check'][] = substr($candidate, 2);
                }
            }
        }

        if (trim($line) === '## Summary') {
            foreach (array_slice($lines, $index + 1, 4) as $candidate) {
                if (str_starts_with($candidate, '- ')) {
                    $summary['summary'][] = substr($candidate, 2);
                }
            }
        }

        if (trim($line) === '## Community Hubs (Navigation)') {
            foreach (array_slice($lines, $index + 1, 12) as $candidate) {
                if (! str_starts_with($candidate, '- [[')) {
                    continue;
                }

                $label = preg_replace('/^- \[\[[^|]+\|(.+)\]\]$/', '$1', trim($candidate));

                if (is_string($label)) {
                    $summary['community_hubs'][] = $label;
                }
            }
        }
    }

    return $summary;
}

function totalCoreCodeFiles(): int
{
    $directories = [
        'app/Models',
        'app/Http/Controllers',
        'app/Actions',
        'app/Services',
        'app/Support',
        'app/Livewire',
        'app/Filament',
        'app/Providers',
        'app/Mcp',
    ];

    $count = 0;

    foreach ($directories as $directory) {
        foreach (phpFilesIn($directory) as $_filePath) {
            $count++;
        }
    }

    return $count;
}

/**
 * @return array<string, array<string, mixed>>
 */
function packageAssessments(): array
{
    return [
        'aiarmada/addressing' => [
            'classification' => 'Partial',
            'gap_reason' => 'Compatibility layer still present',
            'priority' => 'High',
            'native_surface' => 'Package models, address traits, and filament-addressing resources are live in runtime and admin.',
            'app_owned_surface' => 'Catalog endpoints and public API filters still expose legacy state/district/subdistrict semantics on top of package address areas.',
            'summary' => 'Addressing is genuinely adopted, but the app still preserves older geography contracts instead of letting generic address-area semantics become primary.',
            'evidence' => [
                'app/Http/Controllers/Api/Admin/CatalogController.php',
                'app/Support/Api/Frontend/FrontendCatalogService.php',
                'app/Http/Controllers/Api/EventController.php',
                'app/Providers/AppServiceProvider.php',
            ],
            'next_steps' => [
                'Decide whether v1 API keeps legacy geography parameter names or moves to generic address-area contracts.',
                'Minimize duplicated country/area catalog logic where package resources or generic endpoints can own it.',
            ],
        ],
        'aiarmada/affiliates' => [
            'classification' => 'Partial',
            'gap_reason' => 'App-specific layer intentionally retained',
            'priority' => 'Medium',
            'native_surface' => 'Affiliate links, attributions, conversions, and balances come from the affiliates package.',
            'app_owned_surface' => 'Share payload generation, redirect flows, dakwah-specific share outcomes, and signals bridging are local services.',
            'summary' => 'The app uses the affiliates domain natively for storage and attribution, but retains a deliberate product layer for share UX and analytics semantics.',
            'evidence' => [
                'app/Services/ShareTracking/AffiliatesShareTrackingService.php',
                'app/Services/Signals/AffiliateSignalsBridge.php',
                'app/Models/User.php',
            ],
            'next_steps' => [
                'Keep app-specific share copy and outcome semantics local.',
                'Push only generic attribution seams back into the package if multiple apps would share them.',
            ],
        ],
        'aiarmada/authz' => [
            'classification' => 'Adopted',
            'gap_reason' => 'No major gap',
            'priority' => 'Low',
            'native_surface' => 'Authz runtime and filament-authz routes are active, including impersonation and role/permission resources.',
            'app_owned_surface' => 'App policies and membership-role presentation remain local as product-level authorization composition.',
            'summary' => 'Authorization is mostly native-package driven at runtime.',
            'evidence' => [
                'app/Providers/Filament/AdminPanelProvider.php',
                'app/Filament/Resources/Authz/UserResource.php',
            ],
            'next_steps' => [
                'Keep app-level policies only where the package cannot encode product-specific rules.',
            ],
        ],
        'aiarmada/commerce-support' => [
            'classification' => 'Partial',
            'gap_reason' => 'Compatibility layer still present',
            'priority' => 'High',
            'native_surface' => 'OwnerContext, shared package models, and support primitives are widely used across events, reports, saved searches, and MCP/admin surfaces.',
            'app_owned_surface' => 'The app still wraps package models like Report and SavedSearch and adds presentation/serialization layers around them.',
            'summary' => 'Commerce Support is a real foundation dependency, but a few app wrappers still sit between the package and public/admin contracts.',
            'evidence' => [
                'app/Models/Report.php',
                'app/Models/SavedSearch.php',
                'app/Support/Api/Admin/AdminResourceRegistry.php',
                'app/Mcp/Tools/Admin/AbstractAdminTool.php',
            ],
            'next_steps' => [
                'Remove or thin wrappers once API/MCP serializers can talk directly to package models.',
            ],
        ],
        'aiarmada/communications' => [
            'classification' => 'Partial',
            'gap_reason' => 'Native package installed but surface not fully implemented',
            'priority' => 'Critical',
            'native_surface' => 'Package inbox model, communication resolvers, webhook route, and filament communications resources are active.',
            'app_owned_surface' => 'NotificationEngine, NotificationSettingsManager, PendingNotification lifecycle, and custom channels remain local.',
            'summary' => 'Communications is the largest remaining mixed-ownership domain. Storage and inbox are package-backed, but orchestration is still mostly app code.',
            'evidence' => [
                'app/Services/Notifications/NotificationEngine.php',
                'app/Services/Notifications/NotificationSettingsManager.php',
                'app/Notifications/Channels/InboxChannel.php',
                'docs/aiarmada-adoption/status.md',
            ],
            'next_steps' => [
                'Decide whether to migrate notification orchestration fully into package-native communications workflows.',
                'If full cutover is deferred, explicitly document the app-owned notification boundary as stable product code.',
            ],
        ],
        'aiarmada/contacting' => [
            'classification' => 'Adopted',
            'gap_reason' => 'App-specific layer intentionally retained',
            'priority' => 'Low',
            'native_surface' => 'Package contact/social profile traits back institutions, speakers, venues, references, and event submissions.',
            'app_owned_surface' => 'Presentation aliases and form composition remain local.',
            'summary' => 'Contacting is natively embedded in the data model; remaining local code is mostly presentation and form ergonomics.',
            'evidence' => [
                'app/Models/Institution.php',
                'app/Models/Speaker.php',
                'app/Models/Venue.php',
                'app/Filament/Resources/Institutions/Schemas/InstitutionForm.php',
            ],
            'next_steps' => [
                'Keep public formatting local unless the same formatting rules become reusable across apps.',
            ],
        ],
        'aiarmada/engagement' => [
            'classification' => 'Partial',
            'gap_reason' => 'App-specific layer intentionally retained',
            'priority' => 'Medium',
            'native_surface' => 'Package bookmarks, follows, responses, and related traits power events, users, and public save/follow behavior.',
            'app_owned_surface' => 'Public controllers, Livewire state sync, and share-specific engagement semantics remain local.',
            'summary' => 'Engagement is materially adopted, but the app still owns the end-user interaction layer and analytics semantics.',
            'evidence' => [
                'app/Http/Controllers/Api/EventController.php',
                'app/Http/Controllers/Api/EventSaveController.php',
                'app/Livewire/Pages/Events/Show.php',
                'app/Models/User.php',
            ],
            'next_steps' => [
                'Keep package mechanics as source of truth; avoid recreating engagement persistence in local tables or services.',
            ],
        ],
        'aiarmada/events' => [
            'classification' => 'Partial',
            'gap_reason' => 'Compatibility layer still present',
            'priority' => 'Critical',
            'native_surface' => 'Core event, venue, series, registration, attendance, update, submission, and related Filament resources are package-backed.',
            'app_owned_surface' => 'The public event page, parts of the API contract, submission UX, and multiple model wrappers still sit on top of the package.',
            'summary' => 'Events is the main native domain, but it still has the largest app-owned composition layer. That is good for product UX, but still a real upgrade surface.',
            'evidence' => [
                'app/Models/Event.php',
                'app/Livewire/Pages/Events/Show.php',
                'app/Http/Controllers/Api/EventRegistrationController.php',
                'app/Actions/Events/SaveAdminEventAction.php',
            ],
            'next_steps' => [
                'Keep public UX local, but collapse compatibility aliases and wrapper accessors where the package contract is already stable.',
                'Treat legacy filter names and model shims as explicit debt, not invisible defaults.',
            ],
        ],
        'aiarmada/filament-addressing' => [
            'classification' => 'Adopted',
            'gap_reason' => 'No major gap',
            'priority' => 'Low',
            'native_surface' => 'Admin routes for address countries and areas are active.',
            'app_owned_surface' => 'App admin metadata registry adds MCP/API introspection around the package resources.',
            'summary' => 'The plugin is live and useful; remaining local code is composition, not a competing CRUD implementation.',
            'evidence' => [
                'app/Providers/Filament/AdminPanelProvider.php',
                'app/Support/Api/Admin/AdminResourceRegistry.php',
            ],
            'next_steps' => [
                'None beyond keeping package resources the source of truth.',
            ],
        ],
        'aiarmada/filament-authz' => [
            'classification' => 'Adopted',
            'gap_reason' => 'No major gap',
            'priority' => 'Low',
            'native_surface' => 'Role and permission resources plus impersonation routes are package-backed.',
            'app_owned_surface' => 'User resource composition and panel-level role scope config remain local.',
            'summary' => 'Healthy adoption.',
            'evidence' => [
                'app/Providers/Filament/AdminPanelProvider.php',
                'app/Filament/Resources/Authz/UserResource.php',
            ],
            'next_steps' => [
                'Keep custom user-facing composition local, not duplicate role/permission CRUD.',
            ],
        ],
        'aiarmada/filament-communications' => [
            'classification' => 'Partial',
            'gap_reason' => 'Native package installed but surface not fully implemented',
            'priority' => 'High',
            'native_surface' => 'Communication deliveries, batches, preferences, suppressions, and inbox views are active in admin.',
            'app_owned_surface' => 'The runtime notification engine still lives in app code, so the plugin sees only part of the real behavior.',
            'summary' => 'The plugin is live, but the backend communications domain is only partially cut over.',
            'evidence' => [
                'app/Providers/Filament/AdminPanelProvider.php',
                'app/Services/Notifications/NotificationEngine.php',
            ],
            'next_steps' => [
                'Align backend ownership before expanding admin-only package surfaces further.',
            ],
        ],
        'aiarmada/filament-contacting' => [
            'classification' => 'Adopted',
            'gap_reason' => 'No major gap',
            'priority' => 'Low',
            'native_surface' => 'Plugin registration is active and complements package traits.',
            'app_owned_surface' => 'No competing local contacting admin suite was found.',
            'summary' => 'Healthy plugin adoption.',
            'evidence' => [
                'app/Providers/Filament/AdminPanelProvider.php',
            ],
            'next_steps' => [
                'Keep it package-owned.',
            ],
        ],
        'aiarmada/filament-engagement' => [
            'classification' => 'Adopted',
            'gap_reason' => 'No major gap',
            'priority' => 'Low',
            'native_surface' => 'Engagement admin resources are active in the admin panel.',
            'app_owned_surface' => 'Public interaction UX remains local, which is appropriate.',
            'summary' => 'Healthy plugin adoption.',
            'evidence' => [
                'app/Providers/Filament/AdminPanelProvider.php',
            ],
            'next_steps' => [
                'Keep the plugin as the admin source of truth for engagement records.',
            ],
        ],
        'aiarmada/filament-events' => [
            'classification' => 'Adopted',
            'gap_reason' => 'App-specific layer intentionally retained',
            'priority' => 'Medium',
            'native_surface' => 'The biggest active package route surface in the app: events, venues, attendances, change logs, and more across admin and ahli panels.',
            'app_owned_surface' => 'The app adds relation managers, registries, moderation composition, and member/admin API metadata around package resources.',
            'summary' => 'The plugin is heavily adopted. Local code is mostly orchestration and product-specific panel composition.',
            'evidence' => [
                'app/Providers/Filament/AdminPanelProvider.php',
                'app/Providers/Filament/AhliPanelProvider.php',
                'app/Support/Api/Admin/AdminResourceRegistry.php',
            ],
            'next_steps' => [
                'Avoid rebuilding core event CRUD locally; keep extending around package resources.',
            ],
        ],
        'aiarmada/filament-inventory' => [
            'classification' => 'Pending Adoption',
            'gap_reason' => 'Native package installed but surface not yet implemented',
            'priority' => 'High',
            'native_surface' => 'Inventory admin routes are active in the admin panel.',
            'app_owned_surface' => 'No material app controllers, services, or Livewire/MCP flows were found using inventory directly.',
            'summary' => 'Inventory is runtime-live but mostly dormant in app code.',
            'evidence' => [
                'app/Providers/Filament/AdminPanelProvider.php',
                'config/inventory.php',
                'config/events.php',
            ],
            'next_steps' => [
                'Either wire inventory into paid-ticket/event capacity workflows or disable/defer the admin surface until it is real product behavior.',
            ],
        ],
        'aiarmada/filament-seating' => [
            'classification' => 'Pending Adoption',
            'gap_reason' => 'Native package installed but surface not yet implemented',
            'priority' => 'High',
            'native_surface' => 'Seat-map resources and seat-map pages are active in admin.',
            'app_owned_surface' => 'No direct app seating integration was found in public/API/MCP layers.',
            'summary' => 'Seating is enabled but thinly adopted beyond admin runtime.',
            'evidence' => [
                'app/Providers/Filament/AdminPanelProvider.php',
                'config/seating.php',
            ],
            'next_steps' => [
                'If seating is part of the product roadmap, build the event-side selection and allocation flows against the package.',
                'If not, keep it clearly marked as latent capability.',
            ],
        ],
        'aiarmada/filament-signals' => [
            'classification' => 'Adopted',
            'gap_reason' => 'App-specific layer intentionally retained',
            'priority' => 'Low',
            'native_surface' => 'Signals dashboard is the admin home page and package resources are active.',
            'app_owned_surface' => 'The app adds curated analytics pages and integration glue.',
            'summary' => 'Signals admin is strongly package-led; local code adds product-specific reporting and copy.',
            'evidence' => [
                'app/Providers/Filament/AdminPanelProvider.php',
                'app/Filament/Pages/ProductSignals.php',
                'app/Filament/Pages/ShareAnalytics.php',
            ],
            'next_steps' => [
                'Keep package analytics primitives canonical; keep app-specific storytelling/reporting local.',
            ],
        ],
        'aiarmada/filament-ticketing' => [
            'classification' => 'Pending Adoption',
            'gap_reason' => 'Native package installed but surface not yet implemented',
            'priority' => 'High',
            'native_surface' => 'Ticket types, passes, pass holders, and pass transfers are active in admin.',
            'app_owned_surface' => 'No direct app controllers, Livewire pages, or MCP tools use ticketing yet.',
            'summary' => 'Ticketing is present as latent capability, not yet as a first-class product flow.',
            'evidence' => [
                'app/Providers/Filament/AdminPanelProvider.php',
                'config/ticketing.php',
            ],
            'next_steps' => [
                'Adopt ticketing end to end with event registration and pass issuance, or treat it explicitly as future capability.',
            ],
        ],
        'aiarmada/inventory' => [
            'classification' => 'Pending Adoption',
            'gap_reason' => 'Native package installed but surface not yet implemented',
            'priority' => 'High',
            'native_surface' => 'Events config anticipates inventory integration and the filament inventory plugin is live.',
            'app_owned_surface' => 'There is no substantive app-layer inventory workflow outside config and admin plugin registration.',
            'summary' => 'Inventory is available but not yet materially adopted.',
            'evidence' => [
                'config/events.php',
                'config/inventory.php',
            ],
            'next_steps' => [
                'Tie inventory to event quotas/paid capacity when that commerce chain is activated.',
            ],
        ],
        'aiarmada/membership' => [
            'classification' => 'Partial',
            'gap_reason' => 'App-specific layer intentionally retained',
            'priority' => 'High',
            'native_surface' => 'Membership applications, invitations, roles, hooks, and relation managers are package-backed.',
            'app_owned_surface' => 'Claim pages, presenters, custom workspace behavior, and review APIs remain local.',
            'summary' => 'Membership is deeply adopted but still wrapped in app-specific claim and workspace flows.',
            'evidence' => [
                'app/Models/MembershipApplication.php',
                'app/Models/MemberInvitation.php',
                'app/Livewire/Pages/MembershipClaims/Create.php',
                'app/Support/Membership/AppMembershipHook.php',
            ],
            'next_steps' => [
                'Keep claim UX local, but continue deleting any remaining compatibility aliases that duplicate package status and invitation behavior.',
            ],
        ],
        'aiarmada/moderation' => [
            'classification' => 'Partial',
            'gap_reason' => 'App-specific layer intentionally retained',
            'priority' => 'Medium',
            'native_surface' => 'ModerationReview extends package moderation actions.',
            'app_owned_surface' => 'Report categories, review flows, and broader moderation workflow are still app-composed.',
            'summary' => 'Moderation uses the package as a base but does not yet hand over the full workflow.',
            'evidence' => [
                'app/Models/ModerationReview.php',
                'docs/aiarmada-adoption/domain-mapping.md',
            ],
            'next_steps' => [
                'Assess whether the remaining workflow is truly product-specific or should move toward package-native moderation/reporting seams.',
            ],
        ],
        'aiarmada/references' => [
            'classification' => 'Partial',
            'gap_reason' => 'App-specific layer intentionally retained',
            'priority' => 'Medium',
            'native_surface' => 'Reference storage is package-backed through the wrapper model.',
            'app_owned_surface' => 'Public reference pages, display helpers, and taxonomy decisions remain local.',
            'summary' => 'Reference persistence is native; presentation is still app-owned.',
            'evidence' => [
                'app/Models/Reference.php',
                'app/Support/Api/Frontend/FrontendCatalogService.php',
            ],
            'next_steps' => [
                'Do not duplicate reference persistence locally; keep only public/product presentation in app code.',
            ],
        ],
        'aiarmada/seating' => [
            'classification' => 'Pending Adoption',
            'gap_reason' => 'Native package installed but surface not yet implemented',
            'priority' => 'High',
            'native_surface' => 'Seating package is installed and configured.',
            'app_owned_surface' => 'No direct app namespace references were found in models, controllers, services, Livewire, MCP, or tests.',
            'summary' => 'Pure latent capability right now.',
            'evidence' => [
                'config/seating.php',
                'app/Providers/Filament/AdminPanelProvider.php',
            ],
            'next_steps' => [
                'Adopt through event admission flows or keep the package clearly dormant.',
            ],
        ],
        'aiarmada/signals' => [
            'classification' => 'Partial',
            'gap_reason' => 'App-specific layer intentionally retained',
            'priority' => 'Medium',
            'native_surface' => 'Signals collection endpoints and tracked-property primitives are live.',
            'app_owned_surface' => 'The app still owns event naming, tracker composition, affiliate bridging, and product-specific analytics services.',
            'summary' => 'Signals is healthy and live, but the app adds a fairly thick curation layer on top.',
            'evidence' => [
                'app/Services/Signals/SignalsTracker.php',
                'app/Services/Signals/ProductSignalsService.php',
                'app/Services/Signals/AffiliateSignalsBridge.php',
                'app/Providers/AppServiceProvider.php',
            ],
            'next_steps' => [
                'Keep curated event naming local, but avoid drifting into a parallel analytics persistence layer.',
            ],
        ],
        'aiarmada/ticketing' => [
            'classification' => 'Pending Adoption',
            'gap_reason' => 'Native package installed but surface not yet implemented',
            'priority' => 'High',
            'native_surface' => 'Ticketing package is installed and configured.',
            'app_owned_surface' => 'No direct app namespace references were found outside plugin/config runtime.',
            'summary' => 'Ticketing is available but not yet used as an end-to-end application capability.',
            'evidence' => [
                'config/ticketing.php',
                'app/Providers/Filament/AdminPanelProvider.php',
            ],
            'next_steps' => [
                'Adopt ticketing through registration, pass issuance, and check-in workflows when the product is ready.',
            ],
        ],
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function gapRegister(): array
{
    return [
        [
            'id' => 'G001',
            'title' => 'Communications orchestration is still mostly app-owned',
            'priority' => 'Critical',
            'type' => 'Pending adoption',
            'packages' => ['aiarmada/communications', 'aiarmada/filament-communications'],
            'summary' => 'Inbox/webhook/admin package surfaces are active, but notification orchestration, settings, cadence handling, and delivery flow remain local.',
            'evidence' => [
                'app/Services/Notifications/NotificationEngine.php',
                'app/Services/Notifications/NotificationSettingsManager.php',
                'docs/aiarmada-adoption/status.md',
            ],
            'why_it_matters' => 'This is the clearest long-term drift risk because package tables and admin surfaces exist while behavior still lives elsewhere.',
            'recommended_packet' => 'Define a hard boundary: either complete the communications cutover or formally stabilize the local notification engine as app-owned product code.',
        ],
        [
            'id' => 'G002',
            'title' => 'Legacy geography vocabulary still leaks through API contracts',
            'priority' => 'High',
            'type' => 'Compatibility layer',
            'packages' => ['aiarmada/addressing'],
            'summary' => 'The app uses package address areas internally, but admin/public APIs still publish state/district/subdistrict semantics.',
            'evidence' => [
                'app/Http/Controllers/Api/Admin/CatalogController.php',
                'app/Support/Api/Frontend/FrontendCatalogService.php',
                'app/Http/Controllers/Api/EventController.php',
            ],
            'why_it_matters' => 'This makes package-native global geography harder to expose cleanly and preserves Malaysia-shaped contract debt.',
            'recommended_packet' => 'Freeze legacy names only at explicit v1 compatibility edges; move internal and new-contract surfaces to generic address-area terminology.',
        ],
        [
            'id' => 'G003',
            'title' => 'Fifteen app models still wrap AIArmada package models',
            'priority' => 'High',
            'type' => 'Compatibility layer',
            'packages' => ['aiarmada/events', 'aiarmada/membership', 'aiarmada/moderation', 'aiarmada/references', 'aiarmada/commerce-support'],
            'summary' => 'Core domains are package-backed, but app wrappers still mediate contracts for Event, Registration, Venue, Report, SavedSearch, and related types.',
            'evidence' => [
                'app/Models/Event.php',
                'app/Models/Registration.php',
                'app/Models/Report.php',
                'app/Models/MemberInvitation.php',
            ],
            'why_it_matters' => 'Wrapper models are valid transition seams, but they are also one of the biggest upgrade-drift multipliers.',
            'recommended_packet' => 'Inventory which wrappers only alias package fields and remove those first; keep only wrappers with real product logic.',
        ],
        [
            'id' => 'G004',
            'title' => 'Package config shadow surface is large',
            'priority' => 'High',
            'type' => 'Genuine upgrade risk',
            'packages' => ['aiarmada/events', 'aiarmada/signals', 'aiarmada/inventory', 'aiarmada/filament-authz', 'aiarmada/contacting'],
            'summary' => 'Eighteen local package-related config files exist, including large overrides for events, signals, inventory, and filament-authz.',
            'evidence' => [
                'config/events.php',
                'config/signals.php',
                'config/inventory.php',
                'config/filament-authz.php',
            ],
            'why_it_matters' => 'Published config is a common place for package upgrades to drift silently from runtime behavior.',
            'recommended_packet' => 'Classify every package config as either essential product override or removable default shadow; shrink the second category aggressively.',
        ],
        [
            'id' => 'G005',
            'title' => 'Inventory, seating, and ticketing are live in admin but thin in product code',
            'priority' => 'High',
            'type' => 'Pending adoption',
            'packages' => ['aiarmada/inventory', 'aiarmada/seating', 'aiarmada/ticketing', 'aiarmada/filament-inventory', 'aiarmada/filament-seating', 'aiarmada/filament-ticketing'],
            'summary' => 'Admin/runtime surfaces are enabled, but there is almost no direct app-layer integration in controllers, services, Livewire, MCP, or tests.',
            'evidence' => [
                'app/Providers/Filament/AdminPanelProvider.php',
                'config/events.php',
                'config/seating.php',
                'config/ticketing.php',
            ],
            'why_it_matters' => 'Enabled latent capability creates support and upgrade surface without yet delivering user-facing value.',
            'recommended_packet' => 'Either activate the end-to-end event/ticket/seat/inventory flow or explicitly defer and minimize exposed runtime surface.',
        ],
        [
            'id' => 'G006',
            'title' => 'Membership is deeply adopted but still wrapped in local claim/workspace UX',
            'priority' => 'Medium',
            'type' => 'App-owned by design',
            'packages' => ['aiarmada/membership'],
            'summary' => 'Package models and roles are the source of truth, but claims, invitations display, and workspace presentation stay local.',
            'evidence' => [
                'app/Livewire/Pages/MembershipClaims/Create.php',
                'app/Support/Membership/MembershipClaimPresenter.php',
                'app/Support/Membership/AppMembershipHook.php',
            ],
            'why_it_matters' => 'This is acceptable if deliberate, but it should not drift back into duplicate persistence or role logic.',
            'recommended_packet' => 'Keep product UX local, but continue collapsing any remaining local compatibility around status, invitation, and review mechanics.',
        ],
        [
            'id' => 'G007',
            'title' => 'Event/public API still carries compatibility semantics beyond the package core',
            'priority' => 'Medium',
            'type' => 'Compatibility layer',
            'packages' => ['aiarmada/events', 'aiarmada/addressing', 'aiarmada/engagement'],
            'summary' => 'Public event pages, event registration endpoints, and list filters are still app-composed on top of package models and traits.',
            'evidence' => [
                'app/Livewire/Pages/Events/Show.php',
                'app/Http/Controllers/Api/EventRegistrationController.php',
                'app/Http/Controllers/Api/EventController.php',
            ],
            'why_it_matters' => 'This is expected for product UX, but it should stay composition-only instead of becoming a second event domain.',
            'recommended_packet' => 'Audit event wrappers and filters for package-native equivalents before adding more local event-state logic.',
        ],
        [
            'id' => 'G008',
            'title' => 'Paid commerce package chain is still latent rather than adopted',
            'priority' => 'Medium',
            'type' => 'Pending adoption',
            'packages' => ['aiarmada/cart', 'aiarmada/checkout', 'aiarmada/customers', 'aiarmada/orders', 'aiarmada/pricing', 'aiarmada/products'],
            'summary' => 'The events config anticipates optional commerce integration, but those packages are not required here and no end-to-end paid event flow was found.',
            'evidence' => [
                'config/events.php',
                'docs/aiarmada-adoption/package-inventory.md',
                'docs/aiarmada-adoption/domain-mapping.md',
            ],
            'why_it_matters' => 'The app claims package readiness for advanced ticketing modes, but the full commerce chain is still mostly future-facing.',
            'recommended_packet' => 'If paid or mixed ticket types are a near-term requirement, adopt the commerce chain explicitly instead of relying on dormant config hooks.',
        ],
    ];
}

/**
 * @param  list<array{name: string, path: string, description: string|null}>  $availablePackages
 * @param  list<string>  $directPackageNames
 * @return list<array{name: string, rationale: string, tier: string}>
 */
function opportunityPackages(array $availablePackages, array $directPackageNames): array
{
    $installed = array_fill_keys($directPackageNames, true);

    $interesting = [
        'aiarmada/cart' => ['tier' => 'Commerce chain', 'rationale' => 'Needed only when event registration becomes a real cart flow.'],
        'aiarmada/checkout' => ['tier' => 'Commerce chain', 'rationale' => 'Needed only when paid or mixed event ticket checkout is activated.'],
        'aiarmada/customers' => ['tier' => 'Commerce chain', 'rationale' => 'Dormant unless checkout/order flows are adopted.'],
        'aiarmada/orders' => ['tier' => 'Commerce chain', 'rationale' => 'Dormant unless paid event fulfillment is adopted.'],
        'aiarmada/pricing' => ['tier' => 'Commerce chain', 'rationale' => 'Dormant unless ticket pricing becomes package-owned end to end.'],
        'aiarmada/products' => ['tier' => 'Commerce chain', 'rationale' => 'Dormant unless ticket products/donation bundles become package-owned.'],
        'aiarmada/docs' => ['tier' => 'Commerce support', 'rationale' => 'Useful for invoice/document workflows, not yet required by current app behavior.'],
        'aiarmada/feedback' => ['tier' => 'Moderation/reporting', 'rationale' => 'Potential future candidate if generic feedback/reporting should replace current app report workflow.'],
        'aiarmada/growth' => ['tier' => 'Analytics/growth', 'rationale' => 'Optional layer on top of signals; not currently required.'],
        'aiarmada/affiliate-network' => ['tier' => 'Affiliate expansion', 'rationale' => 'Relevant only if multi-affiliate-network workflows emerge.'],
    ];

    $opportunities = [];

    foreach ($availablePackages as $package) {
        $name = $package['name'];

        if (isset($installed[$name]) || ! isset($interesting[$name])) {
            continue;
        }

        $opportunities[] = [
            'name' => $name,
            'tier' => $interesting[$name]['tier'],
            'rationale' => $interesting[$name]['rationale'],
        ];
    }

    usort($opportunities, static fn (array $left, array $right): int => strcmp($left['name'], $right['name']));

    return $opportunities;
}

/**
 * @return array<string, mixed>
 */
function defaultAssessment(string $packageName): array
{
    return [
        'classification' => 'Partial',
        'gap_reason' => 'Needs manual classification',
        'priority' => 'Medium',
        'native_surface' => 'Installed package surface exists.',
        'app_owned_surface' => 'Audit needs manual detail.',
        'summary' => 'No explicit assessment recorded.',
        'evidence' => [],
        'next_steps' => ['Review this package manually.'],
    ];
}

function routeGroupKeyForPackage(string $packageName): string
{
    return match ($packageName) {
        'aiarmada/filament-communications' => 'Filament',
        'aiarmada/filament-addressing' => 'FilamentAddressing',
        'aiarmada/filament-authz' => 'FilamentAuthz',
        'aiarmada/filament-engagement' => 'FilamentEngagement',
        'aiarmada/filament-events' => 'FilamentEvents',
        'aiarmada/filament-inventory' => 'FilamentInventory',
        'aiarmada/filament-seating' => 'FilamentSeating',
        'aiarmada/filament-signals' => 'FilamentSignals',
        'aiarmada/filament-ticketing' => 'FilamentTicketing',
        'aiarmada/signals' => 'Signals',
        'aiarmada/communications' => 'Communications',
        'aiarmada/authz' => 'Authz',
        default => lastNamespaceSegment((string) (packageNamespaceMap()[$packageName] ?? 'Unknown\\')),
    };
}

function lastNamespaceSegment(string $namespace): string
{
    $segments = array_values(array_filter(explode('\\', trim($namespace, '\\'))));

    return $segments === [] ? 'Unknown' : end($segments);
}

/**
 * @return array<string, string>
 */
function useAliases(string $contents): array
{
    preg_match_all('/use\s+([^;]+);/', $contents, $matches);
    $aliases = [];

    foreach ($matches[1] as $useStatement) {
        $useStatement = trim((string) $useStatement);
        $parts = preg_split('/\s+as\s+/i', $useStatement);
        $fqcn = trim((string) ($parts[0] ?? ''));

        if ($fqcn === '') {
            continue;
        }

        $alias = isset($parts[1]) && is_string($parts[1])
            ? trim($parts[1])
            : basename(str_replace('\\', '/', $fqcn));

        $aliases[$alias] = $fqcn;
    }

    return $aliases;
}

/**
 * @return list<string>
 */
function phpFilesIn(string $directory): array
{
    $path = AUDIT_ROOT.'/'.$directory;

    if (! is_dir($path)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if ($fileInfo->isFile() && $fileInfo->getExtension() === 'php') {
            $files[] = substr($fileInfo->getPathname(), strlen(AUDIT_ROOT) + 1);
        }
    }

    sort($files);

    return $files;
}

function runCommand(string $command): string
{
    $output = shell_exec($command.' 2>&1');

    if (! is_string($output)) {
        throw new RuntimeException(sprintf('Command failed: %s', $command));
    }

    return $output;
}

function renderHtml(array $payload): string
{
    $classificationCounts = [];

    foreach ($payload['packages'] as $package) {
        $classification = (string) $package['classification'];
        $classificationCounts[$classification] = ($classificationCounts[$classification] ?? 0) + 1;
    }

    ksort($classificationCounts);

    $directCount = count($payload['direct_aiarmada_packages']);
    $installedCount = count($payload['installed_aiarmada_packages']);
    $wrapperCount = count($payload['wrapper_models']);
    $traitCount = count($payload['trait_adoptions']);
    $configCount = count($payload['package_config_files']);
    $graphifySummary = $payload['graphify']['summary'] ?? [];
    $graphifyHubs = $payload['graphify']['community_hubs'] ?? [];

    $cards = [
        ['label' => 'Direct AIArmada Packages', 'value' => (string) $directCount],
        ['label' => 'Installed AIArmada Packages', 'value' => (string) $installedCount],
        ['label' => 'Total Routes', 'value' => (string) $payload['route_inventory']['total_routes']],
        ['label' => 'App-Owned Routes', 'value' => (string) $payload['route_inventory']['app_routes']],
        ['label' => 'AIArmada Routes', 'value' => (string) $payload['route_inventory']['aiarmada_routes']],
        ['label' => 'Core PHP Files Audited', 'value' => (string) $payload['layer_file_count']],
        ['label' => 'Wrapper Models', 'value' => (string) $wrapperCount],
        ['label' => 'Package Trait Adoptions', 'value' => (string) $traitCount],
        ['label' => 'Package Config Files', 'value' => (string) $configCount],
    ];

    $jsonPayload = json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    $packageRows = '';

    foreach ($payload['packages'] as $package) {
        $layerList = [];

        foreach ($package['reference_summary']['layers'] as $layer => $layerData) {
            $layerList[] = sprintf('%s (%d)', $layer, $layerData['count']);
        }

        $routeSamples = '';

        foreach ($package['route_samples'] as $sample) {
            $routeSamples .= '<li><code>'.e($sample['method'].' '.$sample['uri']).'</code></li>';
        }

        $evidenceList = '';

        foreach ($package['evidence'] as $evidence) {
            $evidenceList .= '<li><code>'.e($evidence).'</code></li>';
        }

        $nextSteps = '';

        foreach ($package['next_steps'] as $nextStep) {
            $nextSteps .= '<li>'.e($nextStep).'</li>';
        }

        $packageRows .= '<tr>'
            .'<td><strong>'.e($package['name']).'</strong><div class="muted">'.e((string) $package['version']).'</div></td>'
            .'<td><span class="chip chip-'.slugify((string) $package['classification']).'">'.e((string) $package['classification']).'</span><div class="muted">'.e((string) $package['gap_reason']).'</div></td>'
            .'<td>'.e((string) $package['priority']).'</td>'
            .'<td>'.e((string) $package['route_count']).'</td>'
            .'<td>'.e((string) $package['reference_summary']['total_files']).' files / '.e((string) $package['reference_summary']['total_matches']).' hits</td>'
            .'<td>'.e($layerList === [] ? 'None detected' : implode(', ', $layerList)).'</td>'
            .'<td>'.e((string) $package['summary']).'</td>'
            .'<td><details><summary>Details</summary>'
                .'<p><strong>Native surface:</strong> '.e((string) $package['native_surface']).'</p>'
                .'<p><strong>App-owned surface:</strong> '.e((string) $package['app_owned_surface']).'</p>'
                .'<p><strong>Evidence</strong></p><ul>'.$evidenceList.'</ul>'
                .'<p><strong>Route samples</strong></p><ul>'.$routeSamples.'</ul>'
                .'<p><strong>Next steps</strong></p><ul>'.$nextSteps.'</ul>'
            .'</details></td>'
        .'</tr>';
    }

    $ownerRows = '';

    foreach ($payload['route_inventory']['owner_counts'] as $owner => $count) {
        $ownerRows .= '<tr><td>'.e((string) $owner).'</td><td>'.e((string) $count).'</td></tr>';
    }

    $domainRows = '';

    foreach ($payload['route_inventory']['domain_counts'] as $domain => $count) {
        $domainRows .= '<tr><td>'.e((string) $domain).'</td><td>'.e((string) $count).'</td></tr>';
    }

    $groupRows = '';

    foreach ($payload['route_inventory']['aiarmada_routes_by_group'] as $group => $data) {
        $samples = '';

        foreach ($data['samples'] as $sample) {
            $samples .= '<li><code>'.e($sample['method'].' '.$sample['uri']).'</code></li>';
        }

        $groupRows .= '<tr><td>'.e((string) $group).'</td><td>'.e((string) $data['count']).'</td><td><ul>'.$samples.'</ul></td></tr>';
    }

    $wrapperRows = '';

    foreach ($payload['wrapper_models'] as $wrapper) {
        $wrapperRows .= '<tr><td><code>'.e($wrapper['file']).'</code></td><td><code>'.e($wrapper['base_class']).'</code></td></tr>';
    }

    $traitRows = '';

    foreach ($payload['trait_adoptions'] as $traitAdoption) {
        $traitRows .= '<tr><td><code>'.e($traitAdoption['file']).'</code></td><td><code>'.e($traitAdoption['trait']).'</code></td></tr>';
    }

    $configRows = '';

    foreach ($payload['package_config_files'] as $configFile) {
        $configRows .= '<tr><td><code>'.e($configFile['file']).'</code></td><td>'.e((string) $configFile['lines']).'</td></tr>';
    }

    $gapRows = '';

    foreach ($payload['gap_register'] as $gap) {
        $packages = '';
        foreach ($gap['packages'] as $package) {
            $packages .= '<li><code>'.e($package).'</code></li>';
        }

        $evidence = '';
        foreach ($gap['evidence'] as $evidenceItem) {
            $evidence .= '<li><code>'.e($evidenceItem).'</code></li>';
        }

        $gapRows .= '<tr>'
            .'<td><strong>'.e((string) $gap['id']).'</strong></td>'
            .'<td>'.e((string) $gap['title']).'</td>'
            .'<td><span class="chip chip-'.slugify((string) $gap['priority']).'">'.e((string) $gap['priority']).'</span><div class="muted">'.e((string) $gap['type']).'</div></td>'
            .'<td><ul>'.$packages.'</ul></td>'
            .'<td>'.e((string) $gap['summary']).'</td>'
            .'<td><ul>'.$evidence.'</ul></td>'
            .'<td>'.e((string) $gap['why_it_matters']).'</td>'
            .'<td>'.e((string) $gap['recommended_packet']).'</td>'
        .'</tr>';
    }

    $opportunityRows = '';

    foreach ($payload['opportunity_packages'] as $package) {
        $opportunityRows .= '<tr><td><code>'.e($package['name']).'</code></td><td>'.e($package['tier']).'</td><td>'.e($package['rationale']).'</td></tr>';
    }

    $classificationChips = '';

    foreach ($classificationCounts as $classification => $count) {
        $classificationChips .= '<span class="chip chip-'.slugify((string) $classification).'">'.e((string) $classification).': '.e((string) $count).'</span> ';
    }

    $cardHtml = '';

    foreach ($cards as $card) {
        $cardHtml .= '<article class="card stat"><div class="stat-value">'.e($card['value']).'</div><div class="stat-label">'.e($card['label']).'</div></article>';
    }

    $graphifyBullets = '';

    foreach (array_merge($graphifySummary, $graphifyHubs) as $item) {
        $graphifyBullets .= '<li>'.e((string) $item).'</li>';
    }

    return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>AIArmada 360 Adoption Audit</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f4f0e8;
            --surface: #fffaf2;
            --surface-2: #f0eadf;
            --ink: #1f1d1a;
            --muted: #6b655c;
            --line: #d7cdbf;
            --accent: #0f766e;
            --accent-soft: #d7f2ee;
            --danger: #b42318;
            --danger-soft: #fbe2df;
            --warn: #b54708;
            --warn-soft: #fde9d3;
            --ok: #166534;
            --ok-soft: #dcfce7;
            --pending: #4338ca;
            --pending-soft: #e0e7ff;
            --shadow: 0 12px 36px rgba(41, 33, 19, 0.08);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Iowan Old Style", "Palatino Linotype", Georgia, serif;
            background: linear-gradient(180deg, #efe7da 0%, var(--bg) 35%, #f6f2ea 100%);
            color: var(--ink);
            line-height: 1.5;
        }
        header {
            padding: 3rem 2rem 2rem;
            border-bottom: 1px solid rgba(31, 29, 26, 0.08);
            background:
                radial-gradient(circle at top right, rgba(15, 118, 110, 0.12), transparent 28%),
                radial-gradient(circle at top left, rgba(180, 71, 8, 0.10), transparent 30%);
        }
        .wrap {
            width: min(1500px, calc(100vw - 3rem));
            margin: 0 auto;
        }
        h1, h2, h3 {
            font-family: "Avenir Next Condensed", "Franklin Gothic Medium", "Arial Narrow", sans-serif;
            letter-spacing: 0.02em;
            margin: 0 0 0.6rem;
        }
        h1 {
            font-size: clamp(2.4rem, 4vw, 4rem);
            line-height: 0.95;
            text-transform: uppercase;
        }
        h2 {
            margin-top: 0;
            font-size: 1.55rem;
            text-transform: uppercase;
        }
        h3 {
            font-size: 1rem;
            text-transform: uppercase;
            color: var(--muted);
        }
        p, li {
            font-size: 0.98rem;
        }
        main {
            padding: 2rem 0 4rem;
        }
        section {
            margin: 0 0 2rem;
        }
        .intro {
            display: grid;
            grid-template-columns: 2.4fr 1fr;
            gap: 1.5rem;
            align-items: end;
        }
        .lede {
            font-size: 1.08rem;
            max-width: 68rem;
        }
        .meta {
            color: var(--muted);
            font-size: 0.92rem;
        }
        .grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1rem;
        }
        .cards {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1rem;
        }
        .card {
            background: rgba(255, 250, 242, 0.88);
            border: 1px solid var(--line);
            border-radius: 18px;
            padding: 1rem 1.1rem;
            box-shadow: var(--shadow);
            backdrop-filter: blur(6px);
        }
        .stat {
            min-height: 116px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .stat-value {
            font-family: "Avenir Next Condensed", "Franklin Gothic Medium", "Arial Narrow", sans-serif;
            font-size: 2rem;
            font-weight: 700;
        }
        .stat-label,
        .muted {
            color: var(--muted);
        }
        .chip {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            padding: 0.24rem 0.55rem;
            border-radius: 999px;
            border: 1px solid transparent;
            font-size: 0.78rem;
            font-family: "Avenir Next Condensed", "Franklin Gothic Medium", "Arial Narrow", sans-serif;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            white-space: nowrap;
        }
        .chip-adopted { background: var(--ok-soft); color: var(--ok); border-color: rgba(22, 101, 52, 0.14); }
        .chip-partial { background: var(--warn-soft); color: var(--warn); border-color: rgba(181, 71, 8, 0.12); }
        .chip-pending-adoption { background: var(--pending-soft); color: var(--pending); border-color: rgba(67, 56, 202, 0.12); }
        .chip-low { background: var(--accent-soft); color: var(--accent); border-color: rgba(15, 118, 110, 0.12); }
        .chip-medium { background: var(--warn-soft); color: var(--warn); border-color: rgba(181, 71, 8, 0.12); }
        .chip-high,
        .chip-critical { background: var(--danger-soft); color: var(--danger); border-color: rgba(180, 35, 24, 0.12); }
        .pill-row {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            background: rgba(255, 250, 242, 0.84);
            border: 1px solid var(--line);
            border-radius: 16px;
            overflow: hidden;
            box-shadow: var(--shadow);
        }
        th, td {
            padding: 0.85rem 0.9rem;
            text-align: left;
            vertical-align: top;
            border-bottom: 1px solid rgba(215, 205, 191, 0.65);
        }
        th {
            font-family: "Avenir Next Condensed", "Franklin Gothic Medium", "Arial Narrow", sans-serif;
            font-size: 0.84rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            background: rgba(240, 234, 223, 0.9);
        }
        tr:last-child td {
            border-bottom: none;
        }
        code, pre {
            font-family: "SFMono-Regular", Menlo, Consolas, monospace;
            font-size: 0.84rem;
        }
        pre {
            white-space: pre-wrap;
            background: #201d18;
            color: #f8f4ea;
            padding: 1rem;
            border-radius: 16px;
            overflow: auto;
        }
        ul {
            margin: 0.25rem 0 0;
            padding-left: 1.15rem;
        }
        details summary {
            cursor: pointer;
            font-family: "Avenir Next Condensed", "Franklin Gothic Medium", "Arial Narrow", sans-serif;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-size: 0.8rem;
        }
        .section-head {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            align-items: end;
            margin-bottom: 0.85rem;
        }
        .note {
            padding: 1rem 1.1rem;
            border-left: 4px solid var(--accent);
            background: rgba(215, 242, 238, 0.55);
            border-radius: 14px;
        }
        .two-col {
            display: grid;
            grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr);
            gap: 1rem;
        }
        .foot {
            margin-top: 2rem;
            color: var(--muted);
            font-size: 0.88rem;
        }
        @media (max-width: 1100px) {
            .intro,
            .grid,
            .cards,
            .two-col {
                grid-template-columns: 1fr;
            }
            .wrap {
                width: min(1500px, calc(100vw - 1.4rem));
            }
            header {
                padding: 2rem 1rem 1.5rem;
            }
        }
    </style>
</head>
<body>
    <header>
        <div class="wrap">
            <div class="intro">
                <div>
                    <h1>AIArmada 360 Adoption Audit</h1>
                    <p class="lede">This report audits ilmu360 end to end against the AIArmada packages available from <code>/Users/Saiffil/Herd/commerce/packages/*</code>. The goal is to separate package-native ownership from app-built composition, then classify each gap as a genuine package-adoption problem, a compatibility bridge, or an intentional app-owned product boundary.</p>
                    <div class="pill-row">'.$classificationChips.'</div>
                </div>
                <div class="meta">
                    <div><strong>Generated:</strong> '.e($payload['generated_at']).'</div>
                    <div><strong>Report:</strong> <code>docs/aiarmada-adoption/aiarmada-360-audit.html</code></div>
                    <div><strong>Objective:</strong> '.e($payload['objective']).'</div>
                    <div><strong>Method:</strong> Composer state, route inventory, Graphify artifact, docs, and namespace-layer scans.</div>
                </div>
            </div>
        </div>
    </header>
    <main>
        <div class="wrap">
            <section>
                <div class="cards">'.$cardHtml.'</div>
            </section>

            <section class="two-col">
                <article class="card">
                    <div class="section-head">
                        <h2>Executive Read</h2>
                    </div>
                    <p>The application is already strongly package-backed in <strong>events</strong>, <strong>membership</strong>, <strong>addressing</strong>, <strong>contacting</strong>, <strong>engagement</strong>, <strong>signals</strong>, and multiple <strong>Filament</strong> plugins. The real drift risk is not “no adoption”; it is <strong>mixed ownership</strong>: wrappers, compatibility vocabulary, and large local config shadows sitting on top of native package runtime.</p>
                    <p>The most important unresolved gap is <strong>communications</strong>. The package owns inbox tables, resolver contracts, admin resources, and webhooks, but the app still owns most notification orchestration. The second major gap is <strong>latent commerce capability</strong>: inventory, seating, and ticketing are live in admin/runtime but thin in product code.</p>
                    <p>The report treats Graphify as an installed analysis tool. Evidence: <code>AGENTS.md</code> contains Graphify instructions and <code>graphify-out/GRAPH_REPORT.md</code> is present. Graphify is used for codebase understanding, not as an application runtime package.</p>
                </article>
                <article class="card">
                    <div class="section-head">
                        <h2>Graphify View</h2>
                    </div>
                    <div class="note">
                        <p><strong>'.e((string) ($payload['graphify']['header'] ?? 'Graphify report')).'</strong></p>
                        <p>'.e((string) ($payload['graphify']['tooling_note'] ?? '')).'</p>
                    </div>
                    <ul>'.$graphifyBullets.'</ul>
                </article>
            </section>

            <section>
                <div class="section-head">
                    <h2>Runtime Surface</h2>
                    <div class="muted">Route ownership is the fastest proxy for what is truly live versus merely installed.</div>
                </div>
                <div class="grid">
                    <article class="card">
                        <h3>Route Owners</h3>
                        <table>
                            <thead><tr><th>Owner</th><th>Count</th></tr></thead>
                            <tbody>'.$ownerRows.'</tbody>
                        </table>
                    </article>
                    <article class="card">
                        <h3>Domains</h3>
                        <table>
                            <thead><tr><th>Domain</th><th>Count</th></tr></thead>
                            <tbody>'.$domainRows.'</tbody>
                        </table>
                    </article>
                    <article class="card">
                        <h3>Interpretation</h3>
                        <p><strong>209</strong> routes are owned by app controllers/actions and <strong>162</strong> are owned by AIArmada packages. That means package runtime is not hypothetical; it is already a large share of the active route surface.</p>
                        <p>The strongest package route families are <strong>FilamentEvents</strong>, <strong>FilamentSignals</strong>, <strong>FilamentInventory</strong>, <strong>FilamentEngagement</strong>, <strong>FilamentTicketing</strong>, <strong>FilamentAddressing</strong>, <strong>FilamentSeating</strong>, and <strong>Signals</strong>.</p>
                    </article>
                </div>
            </section>

            <section>
                <div class="section-head">
                    <h2>AIArmada Route Groups</h2>
                    <div class="muted">Active runtime entrypoints provided directly by packages.</div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Route Group</th>
                            <th>Count</th>
                            <th>Sample Routes</th>
                        </tr>
                    </thead>
                    <tbody>'.$groupRows.'</tbody>
                </table>
            </section>

            <section>
                <div class="section-head">
                    <h2>Package Matrix</h2>
                    <div class="muted">Each package is classified by actual runtime, code-reference depth, and remaining app-owned surface.</div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Package</th>
                            <th>Status</th>
                            <th>Priority</th>
                            <th>Routes</th>
                            <th>Code References</th>
                            <th>Layers</th>
                            <th>Summary</th>
                            <th>Evidence</th>
                        </tr>
                    </thead>
                    <tbody>'.$packageRows.'</tbody>
                </table>
            </section>

            <section class="two-col">
                <article class="card">
                    <div class="section-head">
                        <h2>Wrapper Models</h2>
                        <div class="muted">Current native-adoption seams that still add app-level indirection.</div>
                    </div>
                    <table>
                        <thead><tr><th>App Model</th><th>Package Base Class</th></tr></thead>
                        <tbody>'.$wrapperRows.'</tbody>
                    </table>
                </article>
                <article class="card">
                    <div class="section-head">
                        <h2>Trait Adoptions</h2>
                        <div class="muted">Thin package-native composition without full wrapper inheritance.</div>
                    </div>
                    <table>
                        <thead><tr><th>App Model</th><th>Package Trait / Concern</th></tr></thead>
                        <tbody>'.$traitRows.'</tbody>
                    </table>
                </article>
            </section>

            <section>
                <div class="section-head">
                    <h2>Config Shadow Surface</h2>
                    <div class="muted">Large local package config files are a concrete long-term upgrade-risk surface.</div>
                </div>
                <table>
                    <thead><tr><th>Config File</th><th>Line Count</th></tr></thead>
                    <tbody>'.$configRows.'</tbody>
                </table>
            </section>

            <section>
                <div class="section-head">
                    <h2>Gap Register</h2>
                    <div class="muted">The goal here is to separate true missing adoption from intentional app ownership and temporary compatibility seams.</div>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Title</th>
                            <th>Priority</th>
                            <th>Packages</th>
                            <th>Summary</th>
                            <th>Evidence</th>
                            <th>Why It Matters</th>
                            <th>Recommended Packet</th>
                        </tr>
                    </thead>
                    <tbody>'.$gapRows.'</tbody>
                </table>
            </section>

            <section class="two-col">
                <article class="card">
                    <div class="section-head">
                        <h2>Not Yet Required</h2>
                        <div class="muted">Available in the sibling commerce workspace but not adopted here.</div>
                    </div>
                    <table>
                        <thead><tr><th>Package</th><th>Tier</th><th>Rationale</th></tr></thead>
                        <tbody>'.$opportunityRows.'</tbody>
                    </table>
                </article>
                <article class="card">
                    <div class="section-head">
                        <h2>Decision Rule</h2>
                    </div>
                    <p><strong>Adopt more package surface</strong> when the app is merely re-encoding generic mechanics that already exist in AIArmada packages.</p>
                    <p><strong>Keep code app-owned</strong> when the remaining layer is clearly product-specific: Malay/Islamic presentation, MCP prompt design, public Livewire composition, or curated analytics semantics.</p>
                    <p><strong>Call it compatibility debt</strong> when the app keeps legacy field names, wrapper aliases, or duplicated config only to preserve older contracts around package-native data.</p>
                </article>
            </section>

            <section>
                <div class="section-head">
                    <h2>Machine Payload</h2>
                    <div class="muted">Future agents can parse this embedded JSON and update the report without re-auditing from zero.</div>
                </div>
                <pre id="audit-json">'.e($jsonPayload ?: '{}').'</pre>
            </section>

            <div class="foot">
                Source documents: '.e(implode(', ', $payload['source_documents'])).'
            </div>
        </div>
    </main>
</body>
</html>';
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?: 'value';

    return trim($value, '-');
}
