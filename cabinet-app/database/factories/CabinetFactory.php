<?php

namespace Database\Factories;

use App\Models\Cabinet;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Cabinet> */
class CabinetFactory extends Factory
{
    protected $model = Cabinet::class;

    public function definition(): array
    {
        return ['nom' => 'Cabinet '.fake()->lastName(), 'code_invitation' => Cabinet::nouveauCode()];
    }
}
