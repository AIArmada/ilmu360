@props([
    'status',
])
@if ($status)
    <div {{ $attributes->merge(['class' => 'sr-only']) }} aria-live="polite" role="status">
            {{ $status }}
        </div>
@endif
