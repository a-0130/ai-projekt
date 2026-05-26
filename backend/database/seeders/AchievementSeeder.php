<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\AchievementService;
use Illuminate\Database\Seeder;

class AchievementSeeder extends Seeder
{
    public function run(): void
    {
        $email = (string) config('seed.user.email');
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            return;
        }

        app(AchievementService::class)->sync($user);
    }
}
