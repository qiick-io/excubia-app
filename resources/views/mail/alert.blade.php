<x-mail::message>
# {{ is_array($alert['project'] ?? null) ? $alert['project']['name'] : app(\App\Services\SettingsRepositoryService::class)->appName() }}

**{{ __('Event') }}:** {{ $alert['event'] }}<br>
**{{ __('Severity') }}:** {{ __(\Illuminate\Support\Str::ucfirst((string) $alert['severity'])) }}

{{ $summary }}

<x-mail::button :url="$alert['url']">
{{ __('Open in :app', ['app' => app(\App\Services\SettingsRepositoryService::class)->appName()]) }}
</x-mail::button>

{{ __('You get this email because alert emails are on in your profile settings.') }}
</x-mail::message>
