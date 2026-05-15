<x-mail::message>
# {{ $title }}

{{ $body }}

@if ($actionUrl)
<x-mail::button :url="$actionUrl">
{{ $actionLabel ?: __('notifications.actions.open') }}
</x-mail::button>
@endif

{{ __('notifications.mail.footer') }}
</x-mail::message>
