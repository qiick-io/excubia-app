<x-mail::message>
# {{ __('Your week in :app', ['app' => app(\App\Services\SettingsRepositoryService::class)->appName()]) }}

{{ __('What changed in the last 7 days in the projects you follow.') }}

@foreach ($projects as $project)
## [{{ $project['name'] }}]({{ $project['url'] }})

@if ($project['newFindingsCount'] > 0)
**{{ __('New findings') }} ({{ $project['newFindingsCount'] }})**

@foreach ($project['newFindings'] as $finding)
- {{ strtoupper($finding['severity']) }} · {{ $finding['text'] }}
@endforeach

@endif
@if ($project['regressions'] !== [])
**{{ __('Regressions') }}**

@foreach ($project['regressions'] as $regression)
- {{ $regression }}
@endforeach

@endif
@if ($project['eol'] !== [])
**{{ __('End of life approaching') }}**

@foreach ($project['eol'] as $eol)
- {{ $eol }}
@endforeach

@endif
@if ($project['exceptions'] !== [])
**{{ __('Top exceptions') }}**

@foreach ($project['exceptions'] as $exception)
- {{ $exception['class'] }} · {{ $exception['where'] }} · {{ trans_choice(':count occurrence|:count occurrences', $exception['count'], ['count' => $exception['count']]) }}
@endforeach

@endif
@if ($project['schedule'] !== [])
**{{ __('Schedule problems') }}**

@foreach ($project['schedule'] as $task)
- {{ $task['task'] }} · {{ __(\Illuminate\Support\Str::ucfirst(str_replace('_', ' ', $task['status']))) }}
@endforeach

@endif
@endforeach

{{ __('You get this email because the weekly digest is on in your profile settings.') }}
</x-mail::message>
