<?php

namespace App\Http\Controllers\Api\V1\Android;

use App\Models\AccountDetail;
use App\Services\Legacy\LegacyEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The account endpoints the new app uses.
 *
 * `get_user_account.php` is the one endpoint in the whole v19 API that already
 * takes its identity from the token rather than from a POST field, and it is
 * the model the rest of this application follows.
 *
 * `update_account.php` and `update_account_details.php` write **one** column
 * named by `field_name`. That is a dynamic column write, which in the old
 * scripts had no allow-list at all on the Apple side (`8/updatefield.php` is
 * `UPDATE accounts set $field='$value' where id=$accountid`, so a caller could
 * set `subscribed`, or `email`, or `hashed_password`). The Android version does
 * have a list; this one has a shorter one, and `email`, both password columns
 * and `subscribed` are not on it under any circumstances.
 */
class AccountController extends Controller
{
    /** Columns `update_account.php` may write. */
    public const ACCOUNT_FIELDS = [
        'nickname', 'icon', 'sobrietydate', 'sobrietytime', 'hp',
        'step2', 'step3', 'step6', 'step7', 'newsletter_subscribed',
        'phone_number', 'fcm_token', 'on_boarding_completed_timestamp',
    ];

    /** Columns `update_account_details.php` may write. */
    public const DETAIL_FIELDS = [
        'phonenumber', 'countrycode', 'country', 'accept_new_sponsees',
        'accept_new_sponsor', 'accept_new_chat', 'age', 'gender', 'profession',
        'about', 'language', 'timezone',
        'notification_morning', 'notification_night',
        'notification_hourly_start', 'notification_hourly_end',
    ];

    public function show(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $details = $account->details;

        return LegacyEnvelope::ok([
            'id' => (int) $account->id,
            'email' => (string) ($account->email ?? ''),
            'nickname' => (string) ($account->nickname ?? ''),
            'icon' => (int) ($account->icon ?? 0),
            'verified' => (int) ($account->verified ?? 0),
            'sobrietydate' => (string) ($account->sobrietydate ?? ''),
            'sobrietytime' => (string) ($account->sobrietytime ?? ''),
            'hp' => (int) ($account->hp ?? 0),
            'step2' => (string) ($account->step2 ?? ''),
            'step3' => (string) ($account->step3 ?? ''),
            'step6' => (string) ($account->step6 ?? ''),
            'step7' => (string) ($account->step7 ?? ''),
            'subscribed' => (int) ($account->subscribed ?? 0),
            'newsletter_subscribed' => (int) ($account->newsletter_subscribed ?? 0),
            'sociallogin' => (int) ($account->sociallogin ?? 0),
            'devicetype' => (int) ($account->devicetype ?? 0),
            'details' => $details === null ? null : [
                'phonenumber' => (string) ($details->phonenumber ?? ''),
                'countrycode' => (string) ($details->countrycode ?? ''),
                'country' => (string) ($details->country ?? ''),
                'age' => (int) ($details->age ?? -1),
                'gender' => (int) ($details->gender ?? -1),
                'profession' => (int) ($details->profession ?? -1),
                'about' => (string) ($details->about ?? ''),
                'accept_new_sponsees' => $details->acceptsNewSponsees(),
                'accept_new_chat' => $details->acceptsNewChat(),
                'language' => (string) ($details->language ?? ''),
                'timezone' => (string) ($details->timezone ?? ''),
                'notification_morning' => (string) ($details->notification_morning ?? ''),
                'notification_night' => (string) ($details->notification_night ?? ''),
                'notification_hourly_start' => (string) ($details->notification_hourly_start ?? ''),
                'notification_hourly_end' => (string) ($details->notification_hourly_end ?? ''),
            ],
        ], 'Account fetched');
    }

    public function updateAccount(Request $request): JsonResponse
    {
        if ($refusal = $this->accountMismatch($request)) {
            return $refusal;
        }

        $field = (string) $request->input('field_name', '');
        if (! in_array($field, self::ACCOUNT_FIELDS, true)) {
            return LegacyEnvelope::fail('Unknown field', 422);
        }

        $account = $this->account($request);
        $account->forceFill([
            $field => (string) $request->input('field_value', ''),
            'modified' => now(),
        ])->save();

        return LegacyEnvelope::ok(['field_name' => $field], 'Account updated');
    }

    public function updateDetails(Request $request): JsonResponse
    {
        if ($refusal = $this->accountMismatch($request)) {
            return $refusal;
        }

        $field = (string) $request->input('field_name', $request->input('option_name', ''));
        if (! in_array($field, self::DETAIL_FIELDS, true)) {
            return LegacyEnvelope::fail('Unknown field', 422);
        }

        $account = $this->account($request);
        $details = AccountDetail::query()->where('accountid', $account->id)->first();
        if ($details === null) {
            $details = new AccountDetail;
            $details->forceFill(['accountid' => $account->id, 'created' => now()]);
        }
        $details->forceFill([
            $field => (string) $request->input('field_value', $request->input('option_value', '')),
            'modified' => now(),
        ])->save();

        return LegacyEnvelope::ok(['field_name' => $field], 'Account details updated');
    }
}
