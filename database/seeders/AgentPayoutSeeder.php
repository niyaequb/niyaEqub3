<?php

namespace Database\Seeders;

use App\Models\AgentPayout;
use Illuminate\Database\Seeder;

class AgentPayoutSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        AgentPayout::factory()->count(3)->create();
    }
}
