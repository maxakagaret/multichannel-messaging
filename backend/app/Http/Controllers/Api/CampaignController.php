<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreCampaignRequest;
use App\Http\Resources\CampaignResource;
use App\Http\Resources\MessageResource;
use App\Messaging\Planning\CampaignLauncher;
use App\Messaging\Stats\DeliveryStats;
use App\Models\Campaign;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CampaignController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return CampaignResource::collection(
            Campaign::withCount(['recipients', 'messages'])->latest('id')->paginate(20)
        );
    }

    public function store(StoreCampaignRequest $request): JsonResponse
    {
        $campaign = Campaign::create($request->payload());

        return CampaignResource::make($campaign)->response()->setStatusCode(201);
    }

    public function show(Campaign $campaign): CampaignResource
    {
        return CampaignResource::make($campaign->loadCount(['recipients', 'messages']));
    }

    public function launch(Campaign $campaign, CampaignLauncher $launcher): JsonResponse
    {
        try {
            $planned = $launcher->launch($campaign);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            'data' => CampaignResource::make($campaign->refresh()->loadCount('recipients')),
            'planned_recipients' => $planned,
        ]);
    }

    public function cancel(Campaign $campaign, CampaignLauncher $launcher): JsonResponse
    {
        try {
            $launcher->cancel($campaign);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json(['data' => CampaignResource::make($campaign->refresh())]);
    }

    public function stats(Campaign $campaign, DeliveryStats $stats): JsonResponse
    {
        return response()->json(['data' => $stats->forCampaign($campaign)]);
    }

    public function messages(Request $request, Campaign $campaign): AnonymousResourceCollection
    {
        $request->validate([
            'status' => ['sometimes', 'string'],
            'channel' => ['sometimes', 'string'],
        ]);

        return MessageResource::collection(
            $campaign->messages()
                ->with(['contact', 'sender'])
                ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')))
                ->when($request->filled('channel'), fn ($q) => $q->where('channel', $request->string('channel')))
                ->latest('id')
                ->paginate(min(100, $request->integer('per_page', 25)))
        );
    }
}
