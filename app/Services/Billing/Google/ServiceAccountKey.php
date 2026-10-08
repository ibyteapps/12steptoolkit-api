<?php

namespace App\Services\Billing\Google;

use App\Services\Billing\StoreCredentialException;
use Firebase\JWT\JWT;

/**
 * One Google service-account JSON key, loaded and checked.
 *
 * ## Why the project matters, and why nobody expects it to
 *
 * A service-account key is issued by a **Google Cloud project**, and the Play
 * Developer API has to be enabled *in that project* — not in whichever project
 * somebody happened to be looking at. The key file names it, in `project_id`,
 * and that is the only reliable statement of which project a call will be
 * billed and authorised against.
 *
 * This matters because the common failure is invisible from the Play Console:
 * the account can be granted every permission there and calls still fail, with
 * a 403 naming a project **number** nobody recognises, because the API is
 * disabled in the project the key came from. Reporting `project_id` and
 * `client_email` from the file itself is what turns that into a one-line fix.
 *
 * Nothing here logs the private key or a minted token.
 */
final class ServiceAccountKey
{
    public const SCOPE = 'https://www.googleapis.com/auth/androidpublisher';

    private function __construct(
        public readonly string $path,
        public readonly string $clientEmail,
        public readonly string $projectId,
        public readonly string $tokenUri,
        private readonly string $privateKeyId,
        private readonly string $privateKey,
    ) {}

    public static function fromConfig(): self
    {
        return self::load((string) config('billing.google.credentials'));
    }

    public static function load(string $path): self
    {
        $path = trim($path);

        if ($path === '') {
            throw new StoreCredentialException('GOOGLE_PLAY_CREDENTIALS is not set');
        }

        if (! is_file($path)) {
            throw new StoreCredentialException("no file at {$path}");
        }

        if (! is_readable($path)) {
            throw new StoreCredentialException("{$path} is not readable by the user PHP runs as");
        }

        $json = json_decode((string) @file_get_contents($path), true);

        if (! is_array($json)) {
            throw new StoreCredentialException('is not valid JSON');
        }

        if (($json['type'] ?? null) !== 'service_account') {
            throw new StoreCredentialException(
                'is JSON, but not a service-account key — `type` is "'.($json['type'] ?? 'missing')
                .'". An OAuth client id file will not do.',
            );
        }

        foreach (['client_email', 'private_key', 'private_key_id', 'project_id'] as $required) {
            if (trim((string) ($json[$required] ?? '')) === '') {
                throw new StoreCredentialException("is a service-account key with no `{$required}`");
            }
        }

        if (@openssl_pkey_get_private($json['private_key']) === false) {
            throw new StoreCredentialException(
                'OpenSSL will not load its private key: '.(openssl_error_string() ?: 'no reason given'),
            );
        }

        return new self(
            path: $path,
            clientEmail: trim($json['client_email']),
            projectId: trim($json['project_id']),
            tokenUri: trim((string) ($json['token_uri'] ?? 'https://oauth2.googleapis.com/token')),
            privateKeyId: trim($json['private_key_id']),
            privateKey: $json['private_key'],
        );
    }

    /**
     * The signed assertion exchanged for an access token.
     *
     * `aud` is the token endpoint from the key file, not a constant: Google
     * rotates the documented endpoint occasionally and the file is the
     * authority on which one this key was issued against.
     */
    public function assertion(string $scope = self::SCOPE): string
    {
        $now = time();

        return JWT::encode([
            'iss' => $this->clientEmail,
            'scope' => $scope,
            'aud' => $this->tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ], $this->privateKey, 'RS256', $this->privateKeyId);
    }

    public function mode(): string
    {
        $mode = @fileperms($this->path);

        return $mode === false ? '????' : substr(sprintf('%o', $mode), -4);
    }

    public function isWorldReadable(): bool
    {
        $mode = @fileperms($this->path);

        return $mode !== false && ($mode & 0o044) !== 0;
    }

    public function isInsideWebRoot(): bool
    {
        $real = realpath($this->path);
        $root = realpath(public_path());

        return $real !== false && $root !== false && str_starts_with($real, $root);
    }
}
