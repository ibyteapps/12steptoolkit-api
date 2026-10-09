<?php

namespace App\Services\Push;

/**
 * One notification, as the transport needs it.
 *
 * `data` is the payload the app routes on — which screen to open — and is
 * deliberately small: everything in it travels through Google's servers.
 */
final class PushMessage
{
    /** @param array<string, string> $data */
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
    ) {}
}
