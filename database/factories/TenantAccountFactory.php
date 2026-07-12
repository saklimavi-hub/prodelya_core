<?php

namespace Database\Factories;

use App\Models\TenantAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantAccountFactory extends Factory
{
    protected $model = TenantAccount::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
            'legal_name' => $this->faker->company(),
            'slug' => $this->faker->unique()->slug(2),
            'panel_subdomain' => $this->faker->unique()->slug(2),
            'status' => 'active',
            'package_key' => 'basic',
            'default_locale' => 'tr',
            'default_currency' => 'TRY',
            'timezone' => 'Europe/Istanbul',
            'number_format_locale' => 'tr_TR',
        ];
    }
}
