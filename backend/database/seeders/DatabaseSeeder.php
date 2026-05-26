<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $this->call([
            TestUserSeeder::class,
            AdminUserSeeder::class,
            AnnouncementExampleSeeder::class,
            TicketTypeSeeder::class,
            GtfsSeeder::class,
            MinimalTestDataSeeder::class,
            RideHistorySeeder::class,
            AchievementSeeder::class,
        ]);
    }
}
