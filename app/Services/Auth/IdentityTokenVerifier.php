<?php

namespace App\Services\Auth;

/** A verified Google or Apple identity. */
final class VerifiedIdentity
{
    public function __construct(
        public readonly string $subject,
        public readonly ?string $email,
        public readonly bool $emailVerified,
    ) {}
}

interface IdentityTokenVerifier
{
    /** Null when the token is not a valid, current token for this application. */
    public function verify(string $token): ?VerifiedIdentity;
}
