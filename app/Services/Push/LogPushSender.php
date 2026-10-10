<?php

namespace App\Services\Push;

use Illuminate\Support\Facades\Log;

/**
 * The default. Writes a line and sends nothing.
 *
 * It logs how many devices would have been reached and what the message was
 * *for* — never a token, which is a credential; never the account it belongs
 * to; and never the title or body, because a comment notification's title is
 * somebody's nickname and its body is about their sponsorship. The routing
 * key and the payload's field names are enough to tell a working send from a
 * broken one, which is all this driver is for.
 */
class LogPushSender implements PushSender
{
    public function send(array $tokens, PushMessage $message): int
    {
        Log::info('push (log driver)', [
            'devices' => count($tokens),
            'table' => $message->table() ?: 'none',
            'fields' => array_keys($message->data),
            'silent' => $message->isSilent(),
        ]);

        return count($tokens);
    }
}
