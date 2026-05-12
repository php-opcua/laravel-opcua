---
eyebrow: 'Docs · Integrations'
lede:    'Routing OPC UA alarms and connection failures through Laravel Notifications — Slack, mail, SMS, database, broadcast. End-to-end example of severity-based on-call routing.'

see_also:
  - { href: '../events/alarm-events.md',         meta: '5 min' }
  - { href: '../events/connection-events.md',    meta: '5 min' }
  - { href: '../recipes/alarm-routing.md',       meta: '5 min' }

prev: { label: 'Livewire',  href: './livewire.md' }
next: { label: 'Filament',  href: './filament.md' }
---

# Notifications

Laravel Notifications turn plant-floor events into messages — to
Slack, mail, SMS, broadcast, database. The package's connection
and alarm events make this a few-line bridge.

## The pattern

1. Listen to a `PhpOpcua\Client\Event\*` class
   (`ConnectionFailed`, `AlarmActivated`,
   `EventNotificationReceived`, …).
2. Resolve who needs to know (an `Notifiable` or a route).
3. Dispatch a `Notification` class.
4. The Notification's `via()` declares which channels.

## End-to-end — severity routing

The goal: route alarms to mail / Slack / pager based on severity.

### The Notification class

<!-- @code-block language="php" label="app/Notifications/PlcAlarmRaised.php" -->
```php
namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\{MailMessage, SlackMessage};
use Illuminate\Notifications\Notification;
use NotificationChannels\Twilio\TwilioChannel;
use NotificationChannels\Twilio\TwilioSmsMessage;

class PlcAlarmRaised extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $eventId,
        public readonly string $source,
        public readonly int $severity,
        public readonly string $message,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($this->severity >= config('alarms.thresholds.email', 400)) {
            $channels[] = 'mail';
        }
        if ($this->severity >= config('alarms.thresholds.slack', 700)) {
            $channels[] = 'slack';
        }
        if ($this->severity >= config('alarms.thresholds.sms', 900)) {
            $channels[] = TwilioChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject("[PLC alarm] {$this->source}")
            ->greeting("Plant alarm — severity {$this->severity}")
            ->line("Source: {$this->source}")
            ->line($this->message)
            ->action('Acknowledge', url("/alarms/{$this->eventId}/ack"));
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        $color = match (true) {
            $this->severity >= 900 => 'danger',
            $this->severity >= 700 => 'warning',
            default                 => 'good',
        };

        return (new SlackMessage())
            ->error()
            ->content("PLC alarm — severity {$this->severity}")
            ->attachment(function ($a) use ($color) {
                $a->title($this->source)
                  ->content($this->message)
                  ->color($color)
                  ->footer('PLC Alarm Pipeline');
            });
    }

    public function toTwilio(object $notifiable): TwilioSmsMessage
    {
        return (new TwilioSmsMessage())
            ->content("PLC alarm sev={$this->severity}: {$this->source} — {$this->message}");
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'event_id' => $this->eventId,
            'source'   => $this->source,
            'severity' => $this->severity,
            'message'  => $this->message,
        ];
    }
}
```
<!-- @endcode-block -->

### Config

<!-- @code-block language="php" label="config/alarms.php" -->
```php
return [
    'thresholds' => [
        'email' => env('ALARM_THRESHOLD_EMAIL', 400),
        'slack' => env('ALARM_THRESHOLD_SLACK', 700),
        'sms'   => env('ALARM_THRESHOLD_SMS',   900),
    ],

    'recipients' => [
        'slack_channel'   => env('ALARM_SLACK_CHANNEL'),
        'oncall_phones'   => array_filter(explode(',', env('ALARM_ONCALL_PHONES', ''))),
        'broadcast_email' => env('ALARM_EMAIL'),
    ],
];
```
<!-- @endcode-block -->

### The listener

<!-- @code-block language="php" label="app/Listeners/RouteAlarmNotification.php" -->
```php
namespace App\Listeners;

use App\Notifications\PlcAlarmRaised;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use PhpOpcua\Client\Event\AlarmActivated;

class RouteAlarmNotification implements ShouldQueue
{
    public string $queue = 'opcua-alarms';

    public function handle(AlarmActivated $event): void
    {
        if (($event->severity ?? 0) < 100) {
            return;
        }

        $notif = new PlcAlarmRaised(
            source:   $event->sourceName ?? 'unknown',
            severity: $event->severity ?? 0,
            message:  $event->message ?? '',
        );

        $notifiable = (new AnonymousNotifiable())
            ->route('mail', config('alarms.recipients.broadcast_email'))
            ->route('slack', config('alarms.recipients.slack_channel'));

        foreach (config('alarms.recipients.oncall_phones', []) as $phone) {
            $notifiable->route(\NotificationChannels\Twilio\TwilioChannel::class, $phone);
        }

        // Also route to operator users who own this line
        $operators = \App\Models\User::role('operator')
            ->whereHas('lines', fn($q) => $q->where('plc_source', $event->sourceName))
            ->get();

        Notification::send($operators, $notif);
        $notifiable->notify($notif);
    }
}
```
<!-- @endcode-block -->

### Register

<!-- @code-block language="php" label="EventServiceProvider" -->
```php
use PhpOpcua\Client\Event\AlarmActivated;

protected $listen = [
    AlarmActivated::class => [
        RouteAlarmNotification::class,
    ],
];
```
<!-- @endcode-block -->

### What happens at runtime

| Severity     | Routing                                                  |
| ------------ | -------------------------------------------------------- |
| 100 - 399    | Database row + operator dashboard notification only      |
| 400 - 699    | + Email to ops mailing list                              |
| 700 - 899    | + Slack to on-call channel                               |
| 900+         | + SMS to on-call phones                                  |

Tune by environment.

## Connection-failure notifications

A different listener, same machinery:

<!-- @code-block language="php" label="connection failure listener" -->
```php
use PhpOpcua\Client\Event\ConnectionFailed;

class NotifyConnectionLost implements ShouldQueue
{
    public string $queue = 'opcua-alerts';

    public function handle(ConnectionFailed $event): void
    {
        // Throttle — one notification per endpoint per 10 minutes
        $key = "conn-fail-notif:{$event->endpointUrl}";
        if (\Cache::has($key)) return;
        \Cache::put($key, true, minutes: 10);

        \Notification::route('slack', config('alerts.ops_slack'))
            ->notify(new PlcConnectionLost(
                endpoint: $event->endpointUrl,
                type:     $event->exception::class,
                message:  $event->exception->getMessage(),
            ));
    }
}
```
<!-- @endcode-block -->

Throttling is essential — a flapping connection can fire 100
`Failed` events per minute. The Cache::add gate makes the
notification at-most-once-per-10-minutes.

The notification itself:

<!-- @code-block language="php" label="app/Notifications/PlcConnectionLost.php" -->
```php
class PlcConnectionLost extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $endpoint,
        public readonly string $type,
        public readonly string $message,
    ) {}

    public function via(): array { return ['slack', 'database']; }

    public function toSlack(): SlackMessage
    {
        return (new SlackMessage())
            ->error()
            ->content("PLC connection lost: {$this->endpoint}")
            ->attachment(function ($a) {
                $a->fields([
                    'Endpoint'  => $this->endpoint,
                    'Exception' => $this->type,
                    'Message'   => $this->message,
                ]);
            });
    }

    public function toDatabase(): array
    {
        return [
            'type'      => 'connection-lost',
            'endpoint'  => $this->endpoint,
            'exception' => $this->type,
            'message'   => $this->message,
        ];
    }
}
```
<!-- @endcode-block -->

## All-clear notifications

When a connection recovers, send the "all clear":

<!-- @code-block language="php" label="reconnect listener" -->
```php
use PhpOpcua\Client\Event\ClientConnected;

class NotifyReconnected implements ShouldQueue
{
    public function handle(ClientConnected $event): void
    {
        // Only if we previously sent a "lost" notification on the same endpoint.
        // ClientConnected fires both on first-connect and on successful
        // reconnect — the throttle key is what distinguishes the two cases.
        $key = "conn-fail-notif:{$event->endpointUrl}";
        if (\Cache::pull($key)) {
            \Notification::route('slack', config('alerts.ops_slack'))
                ->notify(new PlcReconnected(
                    endpoint: $event->endpointUrl,
                ));
        }
    }
}
```
<!-- @endcode-block -->

Reusing the throttle cache key — if it's still set, we previously
notified; if not, the failure was brief enough we never paged.

## Database notifications — the UI surface

A common need: a bell icon in the UI showing recent alarms.

<!-- @code-block language="php" label="controller — fetch notifications" -->
```php
class NotificationsController
{
    public function index(): JsonResponse
    {
        $unread = auth()->user()
            ->unreadNotifications()
            ->where('type', PlcAlarmRaised::class)
            ->limit(20)
            ->get();

        return response()->json($unread);
    }

    public function markRead(string $id): JsonResponse
    {
        auth()->user()->notifications()->findOrFail($id)->markAsRead();
        return response()->json(['ok' => true]);
    }
}
```
<!-- @endcode-block -->

The `notifications` table is built-in (Laravel migration:
`make:notifications-table`). The `data` column holds the JSON
the `toDatabase()` method returned.

## On-call rotation

For a rotating on-call schedule, look up the **current** on-call
phones from a per-shift table:

<!-- @code-block language="php" label="dynamic on-call" -->
```php
use PhpOpcua\Client\Event\AlarmActivated;

public function handle(AlarmActivated $event): void
{
    $oncall = \App\Models\OncallRoster::current()->get();

    foreach ($oncall as $person) {
        $person->notify(new PlcAlarmRaised(/* ... */));
    }
}
```
<!-- @endcode-block -->

`OncallRoster::current()` is your domain — typically a query like
`where('start_at', '<=', now())->where('end_at', '>=', now())`.

## Combining with broadcasting

A "live" alarms page using broadcasting alongside notifications:

<!-- @code-block language="php" label="dual notification" -->
```php
class PlcAlarmRaised extends Notification implements ShouldQueue, ShouldBroadcast
{
    use Queueable;

    public function via(): array { return ['database', 'slack', 'broadcast']; }

    public function toBroadcast(): \Illuminate\Notifications\Messages\BroadcastMessage
    {
        return new \Illuminate\Notifications\Messages\BroadcastMessage([
            'event_id' => $this->eventId,
            'source'   => $this->source,
            'severity' => $this->severity,
            'message'  => $this->message,
        ]);
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel("App.Models.User.{$this->notifiable_id}")];
    }
}
```
<!-- @endcode-block -->

The browser receives the broadcast immediately; the database
record provides history.

## Testing notifications

<!-- @code-block language="php" label="test" -->
```php
use Illuminate\Support\Facades\Notification;
use PhpOpcua\Client\Event\AlarmActivated;
use PhpOpcua\Client\OpcUaClientInterface;

it('sends an alarm to slack on high severity', function () {
    Notification::fake();

    $client = Mockery::mock(OpcUaClientInterface::class);

    event(new AlarmActivated(
        client:         $client,
        subscriptionId: 1,
        clientHandle:   10,
        sourceName:     'Line A',
        severity:       800,
        message:        'High temp',
    ));

    Notification::assertSentOnDemand(PlcAlarmRaised::class);
});
```
<!-- @endcode-block -->

## Where to read next

- [Recipes · Alarm routing](../recipes/alarm-routing.md) —
  full pipeline with ack endpoint.
- [Filament](./filament.md) — admin UI for the alarm tables.
