<x-mail::message>
# {{ is_array($alert['project'] ?? null) ? $alert['project']['name'] : app(\App\Services\SettingsRepositoryService::class)->appName() }}

@if ($alerts === [])
**{{ __('Event') }}:** {{ $alert['event'] }}<br>
**{{ __('Severity') }}:** {{ __(\Illuminate\Support\Str::ucfirst((string) $alert['severity'])) }}

{{ $summary }}
@else
{{ __('This report raised :count alerts.', ['count' => count($alerts)]) }}

@foreach ($alerts as $item)
- **{{ __(\Illuminate\Support\Str::ucfirst($item['severity'])) }}** · {{ $item['event'] }}: {{ $item['summary'] }}
@endforeach
@endif

<x-mail::button :url="$alert['url']">
{{ __('Open in :app', ['app' => app(\App\Services\SettingsRepositoryService::class)->appName()]) }}
</x-mail::button>

{{ __('You get this email because alert emails are on in your profile settings.') }}
</x-mail::message>
