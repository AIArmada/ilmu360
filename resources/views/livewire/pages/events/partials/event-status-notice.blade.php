@if(in_array((string) $event->status, ['pending', 'cancelled'], true))
    <p role="status" data-testid="event-status-notice" class="mt-6 w-fit rounded-full border border-[#f2c867]/40 bg-[#f2c867]/10 px-4 py-2 text-sm font-semibold text-[#f2c867]">
        {{ (string) $event->status === 'pending' ? __('Pending Approval') : __('cancelled') }}
    </p>
@endif
