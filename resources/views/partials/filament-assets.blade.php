@php
    $defaultStyles = ['app', 'filament/filament'];
    $defaultScripts = ['app', 'filament/support', 'filament/schemas', 'filament/forms', 'filament/actions', 'filament/notifications'];

    /** @var list<string> $styles */
    $styles = $styles ?? ['filament/filament'];
    $stylePackages = array_values(array_diff(array_values(array_unique($styles)), $defaultStyles));

    /** @var list<string> $scripts */
    $scripts = $scripts ?? [];
    $scriptPackages = array_values(array_diff(array_values(array_unique($scripts)), $defaultScripts));
@endphp

@if ($stylePackages !== [])
    @push('head')
        @filamentStyles($stylePackages)
    @endpush
@endif

@if ($scriptPackages !== [])
    @push('scripts')
        @filamentScripts($scriptPackages)
    @endpush
@endif
