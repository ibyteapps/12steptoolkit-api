<?php

namespace Tests;

use App\Models\Account;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Signs in with a real Sanctum token rather than `actingAs`, so the token
     * middleware path is exercised by every test that needs an account.
     */
    protected function signInAs(Account $account, string $device = 'Test device'): string
    {
        $token = $account->createToken($device, ['*'], now()->addDays(30))->plainTextToken;
        $this->withHeader('Authorization', 'Bearer '.$token);

        return $token;
    }
}
