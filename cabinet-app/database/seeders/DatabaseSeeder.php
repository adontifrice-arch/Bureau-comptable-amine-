<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(BaseSeeder::class);
        if (env('SEED_DEMO', true)) $this->call(DemoSeeder::class);
        \App\Support\CabinetCourant::utiliser(null);
    }
}
