<?php

namespace Database\Seeders;

use App\Models\Compte;
use App\Models\Service;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Données indispensables : plan comptable, catalogue de services, compte chef comptable. */
class BaseSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('cabinet.PCN') as [$n, $l]) Compte::firstOrCreate(['numero' => $n], ['libelle' => $l]);
        if (! Service::count()) foreach (config('cabinet.SVC0') as $s) Service::create(['nom' => $s, 'prix' => 0]);
        User::firstOrCreate(['email' => 'admin@cabinet.tn'], [
            'name' => 'Amine Boudaya', 'password' => 'Cabinet2026!', 'role' => 'chef', 'status' => 'active',
        ]);
    }
}
