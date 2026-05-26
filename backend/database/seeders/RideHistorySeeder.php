<?php

namespace Database\Seeders;

use App\Models\GtfsRoute;
use App\Models\GtfsStop;
use App\Models\GtfsTrip;
use App\Models\RideHistory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RideHistorySeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->where('email', (string) config('seed.user.email'))->first();
        if (! $user) {
            return;
        }

        DB::table('gtfs_calendars')->updateOrInsert(
            ['service_id' => 'DEMO_WEEKDAY'],
            [
                'monday' => '1',
                'tuesday' => '1',
                'wednesday' => '1',
                'thursday' => '1',
                'friday' => '1',
                'saturday' => '0',
                'sunday' => '0',
                'start_date' => now()->subYear()->format('Ymd'),
                'end_date' => now()->addYear()->format('Ymd'),
            ]
        );

        $route = GtfsRoute::query()->updateOrCreate(
            ['route_id' => 'DEMO_LINE_14'],
            [
                'route_short_name' => '14',
                'route_long_name' => 'Plac Wilsona — Stadion Narodowy',
                'route_type' => 3,
            ]
        );

        $stops = [
            GtfsStop::query()->updateOrCreate(
                ['stop_id' => 'DEMO_STOP_A'],
                ['stop_name' => 'Plac Wilsona', 'stop_lat' => 52.2694, 'stop_lon' => 20.9314]
            ),
            GtfsStop::query()->updateOrCreate(
                ['stop_id' => 'DEMO_STOP_B'],
                ['stop_name' => 'Centrum', 'stop_lat' => 52.2297, 'stop_lon' => 21.0122]
            ),
            GtfsStop::query()->updateOrCreate(
                ['stop_id' => 'DEMO_STOP_C'],
                ['stop_name' => 'Stadion Narodowy', 'stop_lat' => 52.2394, 'stop_lon' => 21.0456]
            ),
        ];

        $trips = [
            GtfsTrip::query()->updateOrCreate(
                ['trip_id' => 'DEMO_TRIP_001'],
                ['route_id' => $route->id, 'service_id' => 'DEMO_WEEKDAY', 'direction_id' => 0]
            ),
            GtfsTrip::query()->updateOrCreate(
                ['trip_id' => 'DEMO_TRIP_002'],
                ['route_id' => $route->id, 'service_id' => 'DEMO_WEEKDAY', 'direction_id' => 1]
            ),
        ];

        $searches = [
            [$trips[0], $stops[0], $stops[1], now()->subDays(2)->setTime(8, 15)],
            [$trips[0], $stops[1], $stops[2], now()->subDay()->setTime(17, 40)],
            [$trips[1], $stops[2], $stops[0], now()->subHours(5)],
        ];

        foreach ($searches as [$trip, $from, $to, $searchedAt]) {
            RideHistory::query()->firstOrCreate(
                [
                    'user_id' => $user->id,
                    'trip_id' => $trip->id,
                    'from_stop_id' => $from->id,
                    'to_stop_id' => $to->id,
                ],
                [
                    'created_at' => $searchedAt,
                    'duration_minutes' => 28,
                ],
            );
        }
    }
}
