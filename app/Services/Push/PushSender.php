<?php

namespace App\Services\Push;

interface PushSender
{
    /**
     * Send one message to a set of device tokens.
     *
     * @param  array<int, string>  $tokens
     * @return int how many were accepted
     */
    public function send(array $tokens, PushMessage $message): int;
}
