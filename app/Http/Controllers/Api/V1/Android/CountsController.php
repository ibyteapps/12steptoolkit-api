<?php

namespace App\Http\Controllers\Api\V1\Android;

use App\Models\Amend;
use App\Models\Gratitude;
use App\Models\Inventory;
use App\Models\Journal;
use App\Models\Morning;
use App\Models\Night;
use App\Services\Legacy\LegacyEnvelope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `get_counts.php` — six row counts.
 *
 * Deliberately not the Apple `getcounts.php`, which answers a bare array
 * holding one object with thirty-odd keys including the person's email,
 * nickname and sobriety date. Two endpoints with the same name and completely
 * different contracts is one of the clearer symptoms of two front ends over one
 * database; the Apple one is served by its own controller and keeps its shape.
 */
class CountsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        if ($refusal = $this->accountMismatch($request)) {
            return $refusal;
        }

        $id = $this->account($request)->id;

        return LegacyEnvelope::ok([
            'amends' => Amend::query()->ownedBy($id)->count(),
            'gratitudes' => Gratitude::query()->ownedBy($id)->count(),
            'inventories' => Inventory::query()->ownedBy($id)->count(),
            'journals' => Journal::query()->ownedBy($id)->count(),
            'mornings' => Morning::query()->ownedBy($id)->count(),
            'nights' => Night::query()->ownedBy($id)->count(),
        ], 'Counts fetched successfully');
    }
}
