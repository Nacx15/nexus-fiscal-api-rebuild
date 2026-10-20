<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CompanyIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_access_own_company(): void
    {
        $user = User::factory()->create();

        $company = Company::factory()->create();

        $user->companies()->attach($company->id, [
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson(
                "/api/v1/companies/{$company->public_id}"
            );

        $response->assertOk();
    }

    public function test_user_cannot_access_another_company(): void
    {
        $user = User::factory()->create();

        $ownCompany = Company::factory()->create();
        $otherCompany = Company::factory()->create();

        $user->companies()->attach($ownCompany->id, [
            'role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $response = $this
            ->actingAs($user)
            ->getJson(
                "/api/v1/companies/{$otherCompany->public_id}"
            );

        $response->assertForbidden();
    }
}
