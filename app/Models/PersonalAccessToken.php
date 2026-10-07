<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * Sanctum's token, with the two columns this application needs and one helper.
 *
 * `platform` and `device_id` are carried so that a person can be shown their own
 * list of signed-in devices and revoke one, which the old system could not do:
 * its tokens had a 296,000,000-second life and no revocation list at all, so a
 * leaked token was an account for ever.
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $fillable = ['name', 'token', 'abilities', 'expires_at', 'platform', 'device_id'];

    /**
     * Sanctum's guard checks this itself, but this model is also looked up
     * directly — by `VerifyLegacyJwt`, which has to decide what a token is
     * before deciding what it may do — and an expired token must not be
     * mistaken for a valid one there.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
