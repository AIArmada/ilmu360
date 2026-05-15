<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => ($appearance ?? 'system') == 'dark'])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    {{-- Inline script to detect system dark mode preference and apply it immediately --}}
    <script>
        (function() {
            const appearance = '{{ $appearance ?? "system" }}';

            if (appearance === 'system') {
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                if (prefersDark) {
                    document.documentElement.classList.add('dark');
                }
            }
        })();
    </script>

    <style>
        html {
            background-color: oklch(1 0 0);
        }

        html.dark {
            background-color: oklch(0.145 0 0);
        }
    </style>

    <title>Authorize Application - {{ config('app.name', 'MCP Server') }}</title>

    <link rel="icon" type="image/png" href="/favicon-96x96.png" sizes="96x96" />
    <link rel="icon" type="image/svg+xml" href="/favicon.svg" />
    <link rel="shortcut icon" href="/favicon.ico" />
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png" />
    <meta name="apple-mobile-web-app-title" content="Authorize MCP" />
    <link rel="manifest" href="/site.webmanifest" />

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-slate-50 font-sans antialiased text-slate-900 dark:bg-slate-950 dark:text-slate-100">
    <div class="relative flex min-h-screen items-center justify-center px-4 py-10 sm:px-6 lg:px-8">
        <div class="relative w-full max-w-xl">
            <div class="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-xl shadow-slate-900/10 dark:border-slate-800 dark:bg-slate-900 dark:shadow-black/30">
                <div class="px-8 pt-10 sm:px-10">
                    <div class="mx-auto flex h-16 w-16 items-center justify-center rounded-lg bg-emerald-500/10 ring-1 ring-emerald-500/20 dark:bg-emerald-400/10 dark:ring-emerald-400/20">
                        <svg class="h-8 w-8 text-emerald-600 dark:text-emerald-300" stroke="currentColor" fill="none" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 3l7 3v5c0 5-3.5 9.5-7 10-3.5-.5-7-5-7-10V6l7-3z"></path>
                        </svg>
                    </div>

                    <div class="mt-6 text-center">
                        <div class="inline-flex items-center gap-2 rounded-md border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-emerald-700 dark:border-emerald-400/20 dark:bg-emerald-400/10 dark:text-emerald-300">
                            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                            MCP authorization
                        </div>

                        <h1 class="mt-4 text-3xl font-semibold tracking-tight sm:text-4xl">
                            Authorize {{ $client->name }}
                        </h1>

                        <p class="mx-auto mt-3 max-w-lg text-sm leading-6 text-slate-600 dark:text-slate-300">
                            ilmu360° will be able to use the requested MCP scopes on your behalf. Review the access below before continuing.
                        </p>
                    </div>
                </div>

                <div class="space-y-4 px-8 py-8 sm:px-10">
                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-4 dark:border-slate-800 dark:bg-slate-950/50">
                        <div class="text-xs font-semibold uppercase tracking-wide text-slate-500 dark:text-slate-400">
                            Signed in as
                        </div>
                        <div class="mt-2 break-all text-base font-semibold text-slate-900 dark:text-slate-100">
                            {{ $user->email }}
                        </div>
                    </div>

                    @if(count($scopes) > 0)
                        <div>
                            <div class="mb-3 flex items-center justify-between gap-3">
                                <p class="text-sm font-semibold text-slate-900 dark:text-slate-100">Permissions requested</p>

                                <span class="inline-flex items-center rounded-md bg-emerald-50 px-2.5 py-1 text-xs font-medium text-emerald-700 ring-1 ring-inset ring-emerald-200 dark:bg-emerald-400/10 dark:text-emerald-300 dark:ring-emerald-400/20">
                                    {{ count($scopes) }} scope{{ count($scopes) === 1 ? '' : 's' }}
                                </span>
                            </div>

                            <ul class="space-y-3">
                                @foreach($scopes as $scope)
                                    <li class="flex gap-3 rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-400/20 dark:bg-emerald-400/10">
                                        <div class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-md bg-white text-emerald-600 shadow-sm ring-1 ring-emerald-200 dark:bg-slate-900 dark:text-emerald-300 dark:ring-emerald-400/20">
                                            <svg class="h-4 w-4" stroke="currentColor" fill="none" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.25" d="M5 13l4 4L19 7"></path>
                                            </svg>
                                        </div>

                                        <div class="space-y-1">
                                            <div class="text-sm font-medium text-slate-900 dark:text-slate-100">
                                                {{ $scope->description }}
                                            </div>
                                            <div class="text-xs leading-5 text-slate-500 dark:text-slate-400">
                                                Grant this access only if you trust this app.
                                            </div>
                                        </div>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif
                </div>

                <div class="border-t border-slate-200/70 px-8 py-6 dark:border-slate-800 sm:px-10">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <form id="cancelForm" method="POST" action="{{ route('passport.authorizations.deny') }}">
                            @csrf
                            @method('DELETE')
                            <input type="hidden" name="state" value="{{ $request->query('state', '') }}">
                            <input type="hidden" name="client_id" value="{{ $client->id }}">
                            <input type="hidden" name="auth_token" value="{{ $authToken }}">
                            <button type="submit" class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-md border border-slate-300 bg-white px-4 text-sm font-semibold text-slate-700 shadow-sm transition hover:bg-slate-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200 dark:hover:bg-slate-800 dark:focus-visible:ring-offset-slate-950">
                                <svg class="h-4 w-4" stroke="currentColor" fill="none" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                </svg>
                                Cancel
                            </button>
                        </form>

                        <form id="authorizeForm" method="POST" action="{{ route('passport.authorizations.approve') }}">
                            @csrf
                            <input type="hidden" name="state" value="{{ $request->query('state', '') }}">
                            <input type="hidden" name="client_id" value="{{ $client->id }}">
                            <input type="hidden" name="auth_token" value="{{ $authToken }}">
                            <button type="submit" id="authorizeButton" class="inline-flex h-11 w-full items-center justify-center gap-2 rounded-md bg-linear-to-r from-emerald-500 to-emerald-600 px-4 text-sm font-semibold text-white shadow-lg shadow-emerald-600/25 transition hover:from-emerald-600 hover:to-emerald-700 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-slate-950">
                                <span id="authorizeText">Authorize</span>

                                <svg id="loadingSpinner" class="hidden h-4 w-4 animate-spin text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            </button>
                        </form>
                    </div>

                    <p class="mt-4 text-center text-xs leading-5 text-slate-500 dark:text-slate-400">
                        You can revoke this access later from your account settings.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.getElementById('authorizeForm');
            const button = document.getElementById('authorizeButton');
            const authorizeText = document.getElementById('authorizeText');
            const loadingSpinner = document.getElementById('loadingSpinner');
            const cancelForm = document.getElementById('cancelForm');

            form.addEventListener('submit', function() {
                button.disabled = true;
                authorizeText.textContent = 'Authorizing...';
                loadingSpinner.classList.remove('hidden');

                setTimeout(function() {
                    const checkRedirect = setInterval(function() {
                        if (!window.location.href.includes('/oauth/authorize') ||
                            window.location.search.includes('code=') ||
                            window.location.search.includes('error=')) {
                            clearInterval(checkRedirect);
                            window.close();
                        }
                    }, 100);

                    setTimeout(function() {
                        clearInterval(checkRedirect);
                        window.close();
                    }, 5000);
                }, 200);
            });

            if (cancelForm) {
                cancelForm.addEventListener('submit', function() {
                    setTimeout(function() {
                        window.close();
                    }, 200);
                });
            }
        });
    </script>
</body>
</html>
