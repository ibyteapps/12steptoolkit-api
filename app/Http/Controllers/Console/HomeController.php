<?php

namespace App\Http\Controllers\Console;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Entitlement;
use App\Models\SupportTicket;
use Illuminate\View\View;

/**
 * What is waiting, then the week, then the money — in that order, because that
 * is the order somebody opening the console needs them in.
 */
class HomeController extends Controller
{
    public function __invoke(): View
    {
        return view('console.home', [
            'waiting' => [
                'open_tickets' => SupportTicket::query()->where('state', 'open')->count(),
                'answered_tickets' => SupportTicket::query()->where('state', 'answered')->count(),
            ],
            'numbers' => [
                'accounts' => Account::query()->count(),
                'active_subscriptions' => Entitlement::query()->where('is_active', true)->count(),
            ],
        ]);
    }
}
