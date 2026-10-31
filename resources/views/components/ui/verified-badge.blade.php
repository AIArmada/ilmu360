@props(['label' => null])

<span
    {{ $attributes->class('inline-flex shrink-0 items-center align-[-0.18em]') }}
    role="img"
    aria-label="{{ $label ?? __('Disahkan') }}"
>
    <flux:icon.check-badge variant="solid" class="size-[1.1em]" />
</span>
