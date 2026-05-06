<?php

namespace Database\Seeders;

use App\Models\ApprovalTier;
use Illuminate\Database\Seeder;

class ApprovalTierSeeder extends Seeder
{
    public function run(): void
    {
        $tiers = [
            ['document_type' => 'pr', 'min_value' => 0, 'max_value' => 50000000, 'tier_count' => 1, 'is_active' => true],
            ['document_type' => 'pr', 'min_value' => 50000001, 'max_value' => 200000000, 'tier_count' => 2, 'is_active' => true],
            ['document_type' => 'pr', 'min_value' => 200000001, 'max_value' => null, 'tier_count' => 3, 'is_active' => true],
            ['document_type' => 'po', 'min_value' => 0, 'max_value' => 50000000, 'tier_count' => 1, 'is_active' => true],
            ['document_type' => 'po', 'min_value' => 50000001, 'max_value' => 200000000, 'tier_count' => 2, 'is_active' => true],
            ['document_type' => 'po', 'min_value' => 200000001, 'max_value' => null, 'tier_count' => 3, 'is_active' => true],
        ];

        foreach ($tiers as $tier) {
            ApprovalTier::create($tier);
        }
    }
}