<?php

namespace App\Http\Controllers;

use App\Http\Requests\RideHistory\IndexRideHistoryRequest;
use App\Http\Requests\RideHistory\StoreRideHistoryRequest;
use App\Models\RideHistory;
use App\Services\AchievementService;
use Illuminate\Http\JsonResponse;

class RideHistoryController extends Controller
{
    public function __construct(private readonly AchievementService $achievements)
    {
    }

    public function index(IndexRideHistoryRequest $request): JsonResponse
    {
        $rides = RideHistory::query()
            ->with(['trip.route', 'fromStop', 'toStop'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->paginate($request->perPage());

        return response()->json([
            'data' => $rides->getCollection()->map(fn (RideHistory $ride) => $this->ridePayload($ride)),
            'meta' => [
                'current_page' => $rides->currentPage(),
                'last_page' => $rides->lastPage(),
                'per_page' => $rides->perPage(),
                'total' => $rides->total(),
            ],
        ]);
    }

    public function store(StoreRideHistoryRequest $request): JsonResponse
    {
        $data = $request->validated();

        $ride = RideHistory::create([
            'user_id' => $request->user()->id,
            'trip_id' => $data['trip_id'],
            'from_stop_id' => $data['from_stop_id'],
            'to_stop_id' => $data['to_stop_id'],
            'duration_minutes' => $data['duration_minutes'],
            'created_at' => now(),
        ]);

        $ride->load(['trip.route', 'fromStop', 'toStop']);
        $this->achievements->sync($request->user());

        return response()->json([
            'message' => 'Przejazd dodany do historii.',
            'ride' => $this->ridePayload($ride),
        ], 201);
    }

    private function ridePayload(RideHistory $ride): array
    {
        return [
            'id' => $ride->id,
            'trip_id' => $ride->trip_id,
            'trip_code' => $ride->trip?->trip_id,
            'route_short_name' => $ride->trip?->route?->route_short_name,
            'route_long_name' => $ride->trip?->route?->route_long_name,
            'from_stop_id' => $ride->from_stop_id,
            'from_stop_name' => $ride->fromStop?->stop_name,
            'to_stop_id' => $ride->to_stop_id,
            'to_stop_name' => $ride->toStop?->stop_name,
            'duration_minutes' => $ride->duration_minutes,
            'created_at' => $ride->created_at,
        ];
    }
}
