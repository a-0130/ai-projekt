<?php

namespace App\Http\Controllers;

use App\Services\SchedulePdfService;
use App\Services\TransitPlannerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ScheduleController extends Controller
{
    public function __construct(
        private readonly TransitPlannerService $planner,
        private readonly SchedulePdfService $pdfService,
    ) {
    }

    public function stops(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:2000'],
        ]);

        $limit = (int) ($data['limit'] ?? 500);

        return response()->json([
            'stops' => $this->planner->listStops($limit, $data['q'] ?? null),
        ]);
    }

    public function routePattern(int $route_id): JsonResponse
    {
        $pattern = $this->planner->routePattern($route_id);
        if ($pattern === null) {
            return response()->json(['message' => 'Nie znaleziono linii.'], 404);
        }

        return response()->json($pattern);
    }

    public function routesPdf(Request $request): Response|JsonResponse
    {
        $data = $request->validate([
            'route_ids' => ['required', 'string'],
            'date' => ['nullable', 'date'],
        ]);

        $routeIds = collect(explode(',', $data['route_ids']))
            ->map(static fn (string $id): int => (int) trim($id))
            ->filter(static fn (int $id): bool => $id > 0)
            ->unique()
            ->take(20)
            ->values()
            ->all();

        if ($routeIds === []) {
            return response()->json(['message' => 'Wybierz co najmniej jedna linie.'], 422);
        }

        $date = isset($data['date']) ? Carbon::parse($data['date'], 'Europe/Warsaw')->startOfDay() : now('Europe/Warsaw')->startOfDay();
        $pdf = $this->pdfService->build($routeIds, $date);
        $filename = 'rozklad-linii-'.$date->toDateString().'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function routeStopDepartures(Request $request, int $route_id, int $stop_id): JsonResponse
    {
        if (! DB::table('gtfs_routes')->where('id', $route_id)->exists()) {
            return response()->json(['message' => 'Nie znaleziono linii.'], 404);
        }
        if (! DB::table('gtfs_stops')->where('id', $stop_id)->exists()) {
            return response()->json(['message' => 'Nie znaleziono przystanku.'], 404);
        }

        $data = $request->validate([
            'direction_key' => ['nullable', 'integer', 'in:-1,0,1'],
            'trip_pattern_id' => ['nullable', 'integer', 'exists:gtfs_trips,id'],
            'use_trip_endpoints' => ['nullable', 'boolean'],
            'date' => ['nullable', 'date'],
        ]);

        $date = isset($data['date']) ? Carbon::parse($data['date'], 'Europe/Warsaw')->startOfDay() : now('Europe/Warsaw')->startOfDay();
        $directionKey = array_key_exists('direction_key', $data) ? (int) $data['direction_key'] : null;
        $tripPatternId = isset($data['trip_pattern_id']) ? (int) $data['trip_pattern_id'] : null;
        $useTripEndpoints = filter_var($data['use_trip_endpoints'] ?? false, FILTER_VALIDATE_BOOL);

        return response()->json([
            'date' => $date->toDateString(),
            'times' => $this->planner->routeStopDepartures($route_id, $stop_id, $directionKey, $date, $tripPatternId, $useTripEndpoints),
        ]);
    }

    public function stopDepartures(Request $request, int $stop_id): JsonResponse
    {
        if (! DB::table('gtfs_stops')->where('id', $stop_id)->exists()) {
            return response()->json(['message' => 'Nie znaleziono przystanku.'], 404);
        }

        $data = $request->validate([
            'route_id' => ['nullable', 'integer', 'exists:gtfs_routes,id'],
            'date' => ['nullable', 'date'],
        ]);

        $date = isset($data['date']) ? Carbon::parse($data['date'], 'Europe/Warsaw')->startOfDay() : now('Europe/Warsaw')->startOfDay();
        $routeFilter = isset($data['route_id']) ? (int) $data['route_id'] : null;

        return response()->json([
            'date' => $date->toDateString(),
            'departures' => $this->planner->stopBoardDepartures($stop_id, $date, $routeFilter),
        ]);
    }
}
