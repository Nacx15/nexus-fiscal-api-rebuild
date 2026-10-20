<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\PacAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PacAccountEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_pac_credentials_are_encrypted(): void
    {
        $company = Company::factory()->create();

        $account = PacAccount::create([
            'company_id' => $company->id,
            'provider' => 'sw_sapien',
            'environment' => 'sandbox',
            'username' => 'usuario-demo',
            'password' => 'password-super-secreto',
            'active' => true,
        ]);

        $raw = DB::table('pac_accounts')
            ->where('id', $account->id)
            ->first();

        $this->assertNotSame(
            'usuario-demo',
            $raw->username
        );

        $this->assertNotSame(
            'password-super-secreto',
            $raw->password
        );

        $this->assertSame(
            'usuario-demo',
            $account->username
        );

        $this->assertSame(
            'password-super-secreto',
            $account->password
        );
    }
}
