<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\AlertEventEnum;
use App\Services\ReportFindingsService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * One alert (#22), or every alert of one report push, as an in-app notification (#296) for the
 * bell and drawer in the sidebar. Stored by DeliverAlertJob, which is already queued, so this
 * notification is not queued itself, and only through the `database` channel: Excubia has no
 * broadcasting, so the badge updates on navigation (the shared `notifications` prop).
 *
 * The stored data is what the frontend's `toItem` (`lib/notifications.ts`) reads: the event,
 * severity, project and count for a localised title, the summary as body, and the link to where
 * the user acts: the finding (`?finding=`), the findings tab for a group of finding alerts, else
 * the project overview (the dashboard once the project is deleted).
 */
class AlertNotification extends Notification
{
    /** Events about findings, whose notification links to the Findings tab. */
    private const FINDING_EVENTS = [AlertEventEnum::FindingNew, AlertEventEnum::FindingResolved, AlertEventEnum::InfrastructureFailed];

    /** The stored body is cut to this many characters; the drawer shows a few lines. */
    private const BODY_LENGTH = 300;

    /**
     * @param  array<string, mixed>  $alert  the alert payload of AlertDispatcherService (the worst one of a group)
     * @param  string  $summary  the alert's one-line summary; empty for a group
     * @param  list<array{id: string, event: string, severity: string, summary: string}>  $alerts  every alert of a group, worst first; empty for one alert
     */
    public function __construct(public readonly array $alert, public readonly string $summary, public readonly array $alerts = []) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{type: string, alert_id: string, event: string, severity: string, project: array{name: string, slug: string}|null, count: int, events: list<string>, title: string, body: string, url: string}
     */
    public function toArray(object $notifiable): array
    {
        $project = is_array($this->alert['project'] ?? null) ? $this->alert['project'] : null;
        $count = max(1, count($this->alerts));
        $what = $count > 1 ? "{$count} alerts" : (string) $this->alert['event'];

        return [
            'type' => 'alert',
            'alert_id' => (string) $this->alert['id'],
            'event' => (string) $this->alert['event'],
            'severity' => (string) $this->alert['severity'],
            'project' => $project === null ? null : ['name' => (string) $project['name'], 'slug' => (string) $project['slug']],
            'count' => $count,
            'events' => $this->events(),
            // English fallback; the drawer builds a localised title from the fields above.
            'title' => sprintf('[%s] %s%s', Str::upper((string) $this->alert['severity']), $what, $project === null ? '' : ' · '.$project['name']),
            'body' => Str::limit($this->alerts === [] ? $this->summary : $this->alerts[0]['summary'], self::BODY_LENGTH),
            'url' => $this->link($project),
        ];
    }

    /**
     * The distinct events of the notification, worst alert's first.
     *
     * @return list<string>
     */
    private function events(): array
    {
        return $this->alerts === []
            ? [(string) $this->alert['event']]
            : array_values(array_unique(array_column($this->alerts, 'event')));
    }

    /**
     * A path (not a full URL), so the link works whatever host the user opened Excubia on.
     *
     * @param  array<string, mixed>|null  $project
     */
    private function link(?array $project): string
    {
        if ($project === null || $this->alert['event'] === AlertEventEnum::ProjectDeleted->value) {
            return route('dashboard', absolute: false);
        }

        $findingEvents = array_map(fn (AlertEventEnum $event): string => $event->value, self::FINDING_EVENTS);

        if (array_diff($this->events(), $findingEvents) !== []) {
            return route('projects.show', ['project' => $project['slug']], false);
        }

        $findingsTab = route('projects.view', ['project' => $project['slug'], 'view' => 'findings'], false);

        if ($this->alerts !== []) {
            return $findingsTab;
        }

        // The alert's details carry the finding's check id, subject and advisory id.
        $identity = app(ReportFindingsService::class)->identity((array) ($this->alert['data'] ?? []));

        return $findingsTab.'?finding='.rawurlencode($identity);
    }
}
