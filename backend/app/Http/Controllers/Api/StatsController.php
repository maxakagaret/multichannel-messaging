<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Messaging\Stats\DeliveryStats;
use App\Models\Campaign;
use App\Models\Contact;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StatsController extends Controller
{
    public function overview(Request $request, DeliveryStats $stats): JsonResponse
    {
        $hours = min(24 * 14, max(1, $request->integer('hours', 24)));

        return response()->json(['data' => [
            ...$stats->overview(CarbonImmutable::now()->subHours($hours)),
            'contacts' => Contact::count(),
            'campaigns' => Campaign::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
        ]]);
    }
}
