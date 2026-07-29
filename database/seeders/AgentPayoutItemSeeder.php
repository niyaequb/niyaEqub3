<?php

namespace Database\Seeders;

use App\Models\AgentPayoutItem;
use Illuminate\Database\Seeder;

class AgentPayoutItemSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        AgentPayoutItem::factory()->count(3)->create();
    }
}
