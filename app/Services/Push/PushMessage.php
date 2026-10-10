<?php

namespace App\Services\Push;

/**
 * One notification, as the transport needs it.
 *
 * `data` is the payload the app routes on — which screen to open — and is
 * deliberately small: everything in it travels through Google's servers.
 *
 * ## `table` is not optional
 *
 * Both clients route on `data['table']`: the new one by
 * `PushRouter.knownTables` (an unknown or missing value is
 * `IgnoreLink('unknown_table')`), the old one by the same string in its FCM
 * service. Every live v19 script sends it — `['table' => 'COMMENTS']`,
 * `['table' => 'SUBSCRIPTION_GIFTED']`, `['table' => 'REMINDER']` — so a
 * message without it is delivered, dropped, and invisible from the server.
 *
 * ## Displayed, or data-only
 *
 * A message with a title is sent with an FCM `notification` block, which the
 * operating system shows while the app is in the background. {@see silent()}
 * sends no such block: the payload reaches the app's own handler and nothing
 * else, which is what reminders need, because both clients compose and show
 * reminders themselves — the new one suppressing the server's copy when the
 * device has already armed its own (`PushDelivery._reminder`), the old one
 * building the notification in `ManageRemindersFCM`. A `notification` block
 * there would show every morning reminder twice.
 */
final class PushMessage
{
    /** @param array<string, string> $data */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
    ) {}

    /**
     * A data-only message: routed and handled by the app, never shown by the
     * operating system.
     *
     * @param  array<string, string>  $data
     */
    public static function silent(array $data): self
    {
        return new self('', '', $data);
    }

    public function isSilent(): bool
    {
        return $this->title === '' && $this->body === '';
    }

    /** The routing key both clients switch on, for logs and tests. */
    public function table(): string
    {
        return (string) ($this->data['table'] ?? '');
    }
}
