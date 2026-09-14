<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\DigiKeyAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;

class DigiKeyAccountTest extends TestCase
{
    public function test_digikey_account_encryption_decryption()
    {
        $account = new DigiKeyAccount();
        $account->account_name = 'Test Account';
        $account->client_id = 'test_client_id';
        $account->client_secret = 'super_secret_key';
        $account->save();

        $this->assertNotEquals('super_secret_key', $account->client_secret);
        $this->assertEquals('super_secret_key', $account->decrypted_client_secret);

        $account->delete();
    }

    public function test_usable_scope_filters_rate_limited_accounts()
    {
        $acc1 = DigiKeyAccount::create([
            'account_name' => 'Acc 1',
            'client_id' => 'id1',
            'client_secret' => 'sec1',
            'is_active' => true,
            'status' => 'rate_limited',
            'rate_limited_until' => now()->addHours(5),
        ]);

        $acc2 = DigiKeyAccount::create([
            'account_name' => 'Acc 2',
            'client_id' => 'id2',
            'client_secret' => 'sec2',
            'is_active' => true,
            'status' => 'active',
        ]);

        $usable = DigiKeyAccount::usable()->get();

        $this->assertFalse($usable->contains('id', $acc1->id));
        $this->assertTrue($usable->contains('id', $acc2->id));

        $acc1->delete();
        $acc2->delete();
    }
}
