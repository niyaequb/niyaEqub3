<?php

namespace Database\Seeders;

use App\Models\AgentCommission;
use Illuminate\Database\Seeder;

class AgentCommissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        AgentCommission::factory()->count(5)->create();
    }
}
