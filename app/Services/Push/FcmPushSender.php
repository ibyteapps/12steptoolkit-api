<?php

namespace App\Services\Push;

use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Firebase Cloud Messaging, HTTP v1.
 *
 * The same two hops as `Billing\Google\PlayDeveloperApi`: a signed assertion
 * exchanged for an access token, then the token against the API. The scope is
 * the only real difference, so the shape is deliberately familiar.
 *
 * **This has not been exercised against Google.** There is no Firebase key on
 * the server yet and none was handled while writing it, so what is proven is
 * the request it builds, not the reply it gets. `PUSH_DRIVER` stays `log`
 * until somebody has watched a real send.
 *
 * One token per request is FCM v1's shape — there is no multicast in v1 — so
 * a member with three devices costs three calls. A failure on one does not
 * stop the others, because a stale token on an old handset should not cost
 * somebody their reminder on the phone they actually use.
 */
class FcmPushSender implements PushSender
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function send(array $tokens, PushMessage $message): int
    {
        $projectId = (string) config('push.fcm.project_id');

        if ($projectId === '') {
            throw new RuntimeException('FCM_PROJECT_ID is not set');
        }

        $accessToken = $this->accessToken();
        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";
        $accepted = 0;

        foreach ($tokens as $token) {
            try {
                $response = Http::timeout((int) config('push.fcm.timeout', 10))
                    ->withToken($accessToken)
                    ->post($url, ['message' => [
                        'token' => $token,
                        'notification' => ['title' => $message->title, 'body' => $message->body],
                        'data' => $message->data,
                    ]]);

                if ($response->successful()) {
                    $accepted++;

                    continue;
                }

                // The status and FCM's own error code, never the token.
                Log::warning('fcm refused a message', [
                    'status' => $response->status(),
                    'error' => $response->json('error.status'),
                ]);
            } catch (\Throwable $e) {
                Log::warning('fcm send failed', ['reason' => $e->getMessage()]);
            }
        }

        return $accepted;
    }

    /** Cached for fifty of its sixty minutes, as the Play client does. */
    private function accessToken(): string
    {
        return Cache::remember('fcm-access-token', now()->addMinutes(50), function (): string {
            $path = (string) config('push.fcm.credentials');

            if ($path === '' || ! is_readable($path)) {
                throw new RuntimeException('FCM_CREDENTIALS is not set or not readable');
            }

            $key = json_decode((string) file_get_contents($path), true);

            if (! is_array($key) || ! isset($key['client_email'], $key['private_key'])) {
                throw new RuntimeException('FCM_CREDENTIALS is not a service-account key');
            }

            $now = time();
            $assertion = JWT::encode([
                'iss' => $key['client_email'],
                'scope' => self::SCOPE,
                'aud' => $key['token_uri'] ?? 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600,
            ], $key['private_key'], 'RS256', $key['private_key_id'] ?? null);

            $response = Http::asForm()
                ->timeout((int) config('push.fcm.timeout', 10))
                ->post($key['token_uri'] ?? 'https://oauth2.googleapis.com/token', [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ]);

            $token = $response->json('access_token');

            if (! is_string($token) || $token === '') {
                // Google's own words, which distinguish a bad key from a clock
                // that is wrong — but never the assertion itself.
                throw new RuntimeException('token exchange failed: '.(string) $response->json('error', 'unknown'));
            }

            return $token;
        });
    }
}
