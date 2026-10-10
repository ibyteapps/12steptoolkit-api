<?php

namespace App\Services\Newsletter;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The mailing list, over Sendy's HTTP API.
 *
 * ## Why an API and not the database
 *
 * `8/getnewslettersubscribed.php` opens `data_sendy2` — the Sendy install's
 * own database — with this application's credentials, and interpolates the
 * address and the list id into a SELECT against its `subscribers` table. Two
 * applications writing one database is how a schema change in one of them
 * breaks the other silently; and in that script's case it was also an
 * injection. Sendy publishes an API; this uses it.
 *
 * ## Unconfigured is a state, not an error
 *
 * No key, no URL or no list id means {@see configured()} is false and every
 * call answers the "nothing happened" value rather than throwing. The server
 * can run without a mailing list, and a member pressing Subscribe should not
 * meet a 500 because an environment variable is missing on a staging box.
 *
 * ## What is never written down
 *
 * The address. Not in a log line, not in an exception message. A subscriber
 * list for an A.A. app is a list of people in A.A.
 */
class Sendy
{
    public function configured(): bool
    {
        return $this->url() !== '' && $this->key() !== '' && $this->list() !== '';
    }

    /**
     * Add an address to the list.
     *
     * Sendy answers `1` for success and a sentence for everything else —
     * including "Already subscribed.", which is a success as far as anybody
     * pressing the button is concerned.
     */
    public function subscribe(string $email, string $name = ''): bool
    {
        if (! $this->configured()) {
            return false;
        }

        try {
            $response = Http::asForm()
                ->timeout($this->timeout())
                ->post($this->url().'/subscribe', [
                    'api_key' => $this->key(),
                    'name' => $name !== '' ? $name : '12 Step Toolkit',
                    'email' => $email,
                    'list' => $this->list(),
                    // Sendy's "give me a string back rather than a web page".
                    'boolean' => 'true',
                ]);
        } catch (\Throwable $e) {
            Log::warning('sendy subscribe failed', ['reason' => $e->getMessage()]);

            return false;
        }

        $body = trim((string) $response->body());

        return $body === '1' || str_contains(mb_strtolower($body), 'already subscribed');
    }

    /** Where an address stands with the list. */
    public function status(string $email): NewsletterStatus
    {
        if (! $this->configured()) {
            return NewsletterStatus::Unknown;
        }

        try {
            $response = Http::asForm()
                ->timeout($this->timeout())
                ->post($this->url().'/api/subscribers/subscription-status.php', [
                    'api_key' => $this->key(),
                    'email' => $email,
                    'list_id' => $this->list(),
                ]);
        } catch (\Throwable $e) {
            Log::warning('sendy status failed', ['reason' => $e->getMessage()]);

            return NewsletterStatus::Unknown;
        }

        return NewsletterStatus::fromSendy(trim((string) $response->body()));
    }

    private function url(): string
    {
        return rtrim((string) config('services.sendy.url'), '/');
    }

    private function key(): string
    {
        return (string) config('services.sendy.api_key');
    }

    /** The configured list, by the key named in `default_list`. */
    private function list(): string
    {
        $lists = (array) config('services.sendy.lists', []);
        $name = (string) config('services.sendy.default_list', 'toolkit');

        return (string) ($lists[$name] ?? '');
    }

    private function timeout(): int
    {
        return max(1, (int) config('services.sendy.timeout', 10));
    }
}
