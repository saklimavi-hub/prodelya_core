<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return [
            'key' => $this->faker->unique()->slug(2),
            'name' => $this->faker->jobTitle(),
            'description' => $this->faker->sentence(),
            'is_active' => true,
            'is_system' => false,
        ];
    }
}
