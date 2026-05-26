<?php

namespace App\Services;

use App\Models\DiscountCode;
use App\Models\GtfsRoute;
use App\Models\Report;
use App\Models\RideHistory;
use App\Models\User;
use App\Models\UserAchievement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AchievementService
{
    public function sync(User $user): array
    {
        $stats = $this->stats($user);

        DB::transaction(function () use ($user, $stats): void {
            foreach ($this->definitions() as $definition) {
                foreach ($definition['variants'] as $variant) {
                    if ($stats[$definition['metric']] < $variant['threshold']) {
                        continue;
                    }

                    $achievement = UserAchievement::firstOrCreate(
                        [
                            'user_id' => $user->id,
                            'achievement_key' => $definition['key'],
                            'variant_key' => $variant['key'],
                        ],
                        [
                            'name' => $variant['name'],
                            'description' => $variant['description'],
                            'threshold' => $variant['threshold'],
                            'earned_at' => now(),
                        ],
                    );

                    if (! $achievement->discountCode()->exists()) {
                        DiscountCode::create([
                            'user_id' => $user->id,
                            'user_achievement_id' => $achievement->id,
                            'code' => $this->uniqueCode($definition['prefix']),
                            'discount_percent' => $variant['discount_percent'],
                            'expires_at' => now()->addMonths(6),
                        ]);
                    }
                }
            }
        });

        return $this->payload($user, $stats);
    }

    public function stats(User $user): array
    {
        $rides = RideHistory::query()
            ->where('user_id', $user->id)
            ->with('trip:id,route_id')
            ->get(['id', 'trip_id', 'duration_minutes', 'created_at']);

        $uniqueRouteIds = $rides
            ->map(fn (RideHistory $ride) => $ride->trip?->route_id)
            ->filter()
            ->unique()
            ->values();

        $totalRoutes = GtfsRoute::query()->count();
        $coverage = $totalRoutes > 0 ? round(($uniqueRouteIds->count() / $totalRoutes) * 100, 2) : 0.0;
        $reportsCount = Report::query()->where('user_id', $user->id)->count();
        $totalMinutes = (int) $rides->sum(fn (RideHistory $ride) => (int) ($ride->duration_minutes ?? 0));

        return [
            'rides_count' => $rides->count(),
            'unique_routes_count' => $uniqueRouteIds->count(),
            'current_streak_days' => $this->streakDays($rides),
            'reports_count' => $reportsCount,
            'route_coverage_percent' => $coverage,
            'total_minutes' => $totalMinutes,
            'long_rides_count' => $rides->filter(fn (RideHistory $ride) => (int) ($ride->duration_minutes ?? 0) >= 30)->count(),
            'active_days_count' => $rides->map(fn (RideHistory $ride) => $ride->created_at?->toDateString())->filter()->unique()->count(),
            'morning_rides_count' => $rides->filter(fn (RideHistory $ride) => $ride->created_at && $ride->created_at->hour >= 5 && $ride->created_at->hour < 10)->count(),
        ];
    }

    public function definitions(): array
    {
        return [
            [
                'key' => 'first_ride',
                'metric' => 'rides_count',
                'prefix' => 'START',
                'variants' => [
                    ['key' => '1', 'threshold' => 1, 'discount_percent' => 5, 'name' => 'Pierwszy przejazd', 'description' => 'Pierwszy zapisany przejazd w historii.'],
                ],
            ],
            [
                'key' => 'rides_total',
                'metric' => 'rides_count',
                'prefix' => 'JAZDA',
                'variants' => [
                    ['key' => '50', 'threshold' => 50, 'discount_percent' => 8, 'name' => '50 przejazdow', 'description' => '50 zapisanych przejazdow.'],
                    ['key' => '100', 'threshold' => 100, 'discount_percent' => 10, 'name' => '100 przejazdow', 'description' => '100 zapisanych przejazdow.'],
                    ['key' => '200', 'threshold' => 200, 'discount_percent' => 15, 'name' => '200 przejazdow', 'description' => '200 zapisanych przejazdow.'],
                ],
            ],
            [
                'key' => 'unique_routes',
                'metric' => 'unique_routes_count',
                'prefix' => 'TRASA',
                'variants' => [
                    ['key' => '5', 'threshold' => 5, 'discount_percent' => 6, 'name' => '5 unikalnych tras', 'description' => 'Przejazdy na 5 roznych trasach.'],
                    ['key' => '10', 'threshold' => 10, 'discount_percent' => 9, 'name' => '10 unikalnych tras', 'description' => 'Przejazdy na 10 roznych trasach.'],
                    ['key' => '25', 'threshold' => 25, 'discount_percent' => 12, 'name' => '25 unikalnych tras', 'description' => 'Przejazdy na 25 roznych trasach.'],
                ],
            ],
            [
                'key' => 'ride_streak',
                'metric' => 'current_streak_days',
                'prefix' => 'SERIA',
                'variants' => [
                    ['key' => '3', 'threshold' => 3, 'discount_percent' => 6, 'name' => 'Streak 3 dni', 'description' => 'Przejazdy przez 3 kolejne dni.'],
                    ['key' => '7', 'threshold' => 7, 'discount_percent' => 10, 'name' => 'Streak 7 dni', 'description' => 'Przejazdy przez 7 kolejnych dni.'],
                    ['key' => '14', 'threshold' => 14, 'discount_percent' => 14, 'name' => 'Streak 14 dni', 'description' => 'Przejazdy przez 14 kolejnych dni.'],
                    ['key' => '30', 'threshold' => 30, 'discount_percent' => 20, 'name' => 'Streak 30 dni', 'description' => 'Przejazdy przez 30 kolejnych dni.'],
                ],
            ],
            [
                'key' => 'reports_sent',
                'metric' => 'reports_count',
                'prefix' => 'INFO',
                'variants' => [
                    ['key' => '1', 'threshold' => 1, 'discount_percent' => 5, 'name' => 'Pierwsze zgloszenie', 'description' => 'Pierwsze zgloszenie wyslane do obslugi.'],
                    ['key' => '5', 'threshold' => 5, 'discount_percent' => 8, 'name' => '5 zgloszen', 'description' => '5 wyslanych zgloszen.'],
                    ['key' => '10', 'threshold' => 10, 'discount_percent' => 12, 'name' => '10 zgloszen', 'description' => '10 wyslanych zgloszen.'],
                ],
            ],
            [
                'key' => 'route_coverage',
                'metric' => 'route_coverage_percent',
                'prefix' => 'MAPA',
                'variants' => [
                    ['key' => '10', 'threshold' => 10, 'discount_percent' => 6, 'name' => '10 procent tras', 'description' => 'Pokrycie 10 procent dostepnych tras.'],
                    ['key' => '25', 'threshold' => 25, 'discount_percent' => 10, 'name' => '25 procent tras', 'description' => 'Pokrycie 25 procent dostepnych tras.'],
                    ['key' => '50', 'threshold' => 50, 'discount_percent' => 15, 'name' => '50 procent tras', 'description' => 'Pokrycie 50 procent dostepnych tras.'],
                    ['key' => '75', 'threshold' => 75, 'discount_percent' => 20, 'name' => '75 procent tras', 'description' => 'Pokrycie 75 procent dostepnych tras.'],
                ],
            ],
            [
                'key' => 'minutes_total',
                'metric' => 'total_minutes',
                'prefix' => 'CZAS',
                'variants' => [
                    ['key' => '500', 'threshold' => 500, 'discount_percent' => 7, 'name' => '500 minut w trasie', 'description' => '500 minut przejazdow w historii.'],
                    ['key' => '1000', 'threshold' => 1000, 'discount_percent' => 10, 'name' => '1000 minut w trasie', 'description' => '1000 minut przejazdow w historii.'],
                ],
            ],
            [
                'key' => 'long_rides',
                'metric' => 'long_rides_count',
                'prefix' => 'DLUGO',
                'variants' => [
                    ['key' => '10', 'threshold' => 10, 'discount_percent' => 7, 'name' => '10 dluzszych przejazdow', 'description' => '10 przejazdow trwajacych co najmniej 30 minut.'],
                    ['key' => '25', 'threshold' => 25, 'discount_percent' => 10, 'name' => '25 dluzszych przejazdow', 'description' => '25 przejazdow trwajacych co najmniej 30 minut.'],
                ],
            ],
            [
                'key' => 'morning_rides',
                'metric' => 'morning_rides_count',
                'prefix' => 'RANO',
                'variants' => [
                    ['key' => '10', 'threshold' => 10, 'discount_percent' => 7, 'name' => '10 porannych przejazdow', 'description' => '10 przejazdow zapisanych rano.'],
                    ['key' => '30', 'threshold' => 30, 'discount_percent' => 10, 'name' => '30 porannych przejazdow', 'description' => '30 przejazdow zapisanych rano.'],
                ],
            ],
        ];
    }

    private function payload(User $user, array $stats): array
    {
        $earned = UserAchievement::query()
            ->with('discountCode')
            ->where('user_id', $user->id)
            ->orderByDesc('earned_at')
            ->get()
            ->keyBy(fn (UserAchievement $achievement) => $achievement->achievement_key.'.'.$achievement->variant_key);

        $achievements = collect($this->definitions())
            ->flatMap(function (array $definition) use ($stats, $earned): Collection {
                return collect($definition['variants'])->map(function (array $variant) use ($definition, $stats, $earned): array {
                    $key = $definition['key'].'.'.$variant['key'];
                    $achievement = $earned->get($key);
                    $value = $stats[$definition['metric']];

                    return [
                        'key' => $definition['key'],
                        'variant_key' => $variant['key'],
                        'name' => $variant['name'],
                        'description' => $variant['description'],
                        'threshold' => $variant['threshold'],
                        'value' => $value,
                        'progress_percent' => min(100, $variant['threshold'] > 0 ? round(($value / $variant['threshold']) * 100, 2) : 0),
                        'earned_at' => $achievement?->earned_at,
                        'discount_code' => $achievement?->discountCode ? $this->discountPayload($achievement->discountCode) : null,
                    ];
                });
            })
            ->values();

        $discountCodes = DiscountCode::query()
            ->with('achievement')
            ->where('user_id', $user->id)
            ->orderByRaw('used_at is null desc')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (DiscountCode $code) => $this->discountPayload($code));

        return [
            'stats' => $stats,
            'achievements' => $achievements,
            'discount_codes' => $discountCodes,
        ];
    }

    private function discountPayload(DiscountCode $code): array
    {
        return [
            'id' => $code->id,
            'code' => $code->code,
            'discount_percent' => $code->discount_percent,
            'expires_at' => $code->expires_at,
            'used_at' => $code->used_at,
            'is_active' => $code->isActive(),
            'achievement_name' => $code->achievement?->name,
        ];
    }

    private function streakDays(Collection $rides): int
    {
        $dates = $rides
            ->map(fn (RideHistory $ride) => $ride->created_at?->toDateString())
            ->filter()
            ->unique()
            ->sortDesc()
            ->values();

        if ($dates->isEmpty()) {
            return 0;
        }

        $cursor = Carbon::parse($dates->first())->startOfDay();
        $today = today()->startOfDay();

        if ($cursor->lt($today->copy()->subDay())) {
            return 0;
        }

        $streak = 0;
        $dateSet = $dates->flip();

        while ($dateSet->has($cursor->toDateString())) {
            $streak++;
            $cursor->subDay();
        }

        return $streak;
    }

    private function uniqueCode(string $prefix): string
    {
        do {
            $code = $prefix.'-'.Str::upper(Str::random(8));
        } while (DiscountCode::query()->where('code', $code)->exists());

        return $code;
    }
}
