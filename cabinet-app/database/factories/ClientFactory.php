<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Client> */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'nom' => fake()->company(),
            'forme' => 'SARL',
            'type_personne' => 'morale',
            'mission' => 'Tenue + bilan',
            'honoraires_mensuels' => 300,
            'matricule_fiscal' => fake()->numerify('#######').'/A/M/000',
            'compte_comptable' => '411'.fake()->numerify('###'),
            'capital' => 10000,
            'nb_parts' => 1000,
            'valeur_nominale' => 10,
            'adresse' => 'Rue de Marseille, Tunis',
        ];
    }

    public function physique(): static
    {
        return $this->state(['forme' => 'Libéral (BNC)', 'type_personne' => 'physique', 'activite' => 'Profession libérale', 'categories_revenus' => ['bnc'], 'capital' => 0, 'nb_parts' => null, 'valeur_nominale' => null]);
    }
}
