<?php

namespace Database\Seeders;

use App\Models\Cabinet;
use App\Models\Compte;
use App\Models\Service;
use App\Models\User;
use App\Support\CabinetCourant;
use Illuminate\Database\Seeder;

/** Données indispensables : premier cabinet, plan comptable, catalogue de services, compte chef comptable. */
class BaseSeeder extends Seeder
{
    public function run(): void
    {
        $cabinet = Cabinet::orderBy('id')->first() ?? Cabinet::creer('Cabinet Amine Boudaya');
        // Les seeders suivants (DemoSeeder) travaillent dans ce cabinet.
        CabinetCourant::utiliser($cabinet->id);

        Compte::installerPlan($cabinet->id);
        if (! Service::count()) foreach (config('cabinet.SVC0') as $s) Service::create(['nom' => $s, 'prix' => 0]);
        User::firstOrCreate(['email' => 'admin@cabinet.tn'], [
            'cabinet_id' => $cabinet->id, 'name' => 'Amine Boudaya', 'password' => 'Cabinet2026!', 'role' => 'chef', 'status' => 'active',
        ]);
    }
}
