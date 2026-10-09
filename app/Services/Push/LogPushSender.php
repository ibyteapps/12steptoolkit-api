<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Log;

/**
 * The default. Writes a line and sends nothing.
 *
 * It logs how many devices would have been reached and the message type —
 * never a token, which is a credential, and never the account it belongs to.
 */
class LogPushSender implements PushSender
{
    public function send(array $tokens, PushMessage $message): int
    {
        Log::info('push (log driver)', [
            'devices' => count($tokens),
            'title' => $message->title,
            'data' => $message->data,
        ]);

        return count($tokens);
    }
}
