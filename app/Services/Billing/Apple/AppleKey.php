<?php

namespace App\Services\Billing\Apple;

use App\Services\Billing\StoreCredentialException;
use Firebase\JWT\JWT;

/**
 * One App Store `.p8` private key, loaded and checked before anything tries to
 * sign with it.
 *
 * ## Two different keys wear the same file extension
 *
 * App Store Connect → Users and Access → Integrations issues several kinds of
 * key, all of them a `.p8` that looks identical on disk:
 *
 *  * an **In-App Purchase** key, which the *App Store Server API* accepts —
 *    transaction history, subscription statuses, notification history;
 *  * an **App Store Connect API** (Team or Individual) key, which reads the
 *    *catalogue* — products, prices, intro offers — and which the Server API
 *    rejects.
 *
 * Neither works for the other's API, and the failure is a bare `401` with no
 * body explaining why. That is the whole reason `billing:check` probes both
 * APIs separately: the only way to find out what a `.p8` is for is to ask.
 *
 * Nothing here logs the key, its contents or the signed token.
 */
final class AppleKey
{
    private function __construct(
        public readonly string $path,
        public readonly string $keyId,
        public readonly string $issuerId,
        private readonly string $pem,
    ) {}

    /** The key for the App Store **Server** API: purchases and notifications. */
    public static function server(): self
    {
        return self::load(
            path: (string) config('billing.apple.private_key'),
            keyId: (string) config('billing.apple.key_id'),
            issuerId: (string) config('billing.apple.issuer_id'),
            envVar: 'APPLE_PRIVATE_KEY_PATH',
        );
    }

    /** The key for the App Store **Connect** API: the catalogue. */
    public static function connect(): self
    {
        return self::load(
            path: (string) config('billing.apple.connect_private_key'),
            keyId: (string) config('billing.apple.connect_key_id'),
            issuerId: (string) config('billing.apple.connect_issuer_id'),
            envVar: 'neither APPLE_CONNECT_KEY_PATH nor APPLE_PRIVATE_KEY_PATH',
        );
    }

    /**
     * Sign [$claims] with this key, as ES256 with `kid` in the header.
     *
     * The caller supplies every claim, including `aud` and the expiry, because
     * the two Apple APIs want different claim sets and getting that wrong is
     * one of the things this class exists to make visible rather than silent.
     */
    public function sign(array $claims): string
    {
        return JWT::encode($claims, $this->pem, 'ES256', $this->keyId);
    }

    /** File permissions as `0600` would be written, for the output. */
    public function mode(): string
    {
        $mode = @fileperms($this->path);

        return $mode === false ? '????' : substr(sprintf('%o', $mode), -4);
    }

    /** Can anybody on the box read this key? */
    public function isWorldReadable(): bool
    {
        $mode = @fileperms($this->path);

        return $mode !== false && ($mode & 0o044) !== 0;
    }

    /** Is the key inside the document root, where a browser could fetch it? */
    public function isInsideWebRoot(): bool
    {
        $real = realpath($this->path);
        $root = realpath(public_path());

        return $real !== false && $root !== false && str_starts_with($real, $root);
    }

    private static function load(string $path, string $keyId, string $issuerId, string $envVar): self
    {
        $path = trim($path);

        if ($path === '') {
            // Reads correctly for both callers: the Connect key falls back to
            // the Server key's path, so naming only one variable would send
            // somebody to set a variable that was never going to be read.
            throw new StoreCredentialException("{$envVar} is not set");
        }

        if (! is_file($path)) {
            throw new StoreCredentialException("no file at {$path}");
        }

        if (! is_readable($path)) {
            throw new StoreCredentialException("{$path} is not readable by the user PHP runs as");
        }

        $contents = (string) @file_get_contents($path);

        if (! str_contains($contents, 'BEGIN PRIVATE KEY')) {
            throw new StoreCredentialException(
                'does not look like a .p8 — an App Store key begins `-----BEGIN PRIVATE KEY-----`',
            );
        }

        $key = @openssl_pkey_get_private($contents);

        if ($key === false) {
            throw new StoreCredentialException('OpenSSL will not load it: '.(openssl_error_string() ?: 'no reason given'));
        }

        $details = openssl_pkey_get_details($key) ?: [];

        if (($details['type'] ?? null) !== OPENSSL_KEYTYPE_EC) {
            throw new StoreCredentialException(
                'is not an elliptic-curve key — App Store keys are P-256, so this is some other kind of .p8',
            );
        }

        if (trim($keyId) === '') {
            throw new StoreCredentialException('the key loads, but its key id is not configured');
        }

        if (trim($issuerId) === '') {
            throw new StoreCredentialException('the key loads, but its issuer id is not configured');
        }

        return new self($path, trim($keyId), trim($issuerId), $contents);
    }
}
