<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Models\Credential;
use App\Services\CredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;

class CredentialServiceTest extends TestCase
{

    public function test_database_credential_wins_over_env()
    {
        putenv('TEST_UNIT_KEY=ENV_VALUE');

        Credential::where('group', 'test')->where('key', 'unit_key')->delete();

        Credential::create([
            'group' => 'test',
            'key' => 'unit_key',
            'value' => Crypt::encryptString('DB_VALUE'),
        ]);

        $resolved = CredentialService::get('test', 'unit_key', 'TEST_UNIT_KEY');

        $this->assertEquals('DB_VALUE', $resolved);

        Credential::where('group', 'test')->where('key', 'unit_key')->delete();
    }

    public function test_env_fallback_when_not_in_database()
    {
        putenv('TEST_FALLBACK_KEY=ENV_FALLBACK_VALUE');

        $resolved = CredentialService::get('test', 'missing_key', 'TEST_FALLBACK_KEY');

        $this->assertEquals('ENV_FALLBACK_VALUE', $resolved);
    }

    public function test_default_value_when_missing_both_db_and_env()
    {
        $resolved = CredentialService::get('test', 'non_existent_key', 'NON_EXISTENT_ENV_KEY', 'DEFAULT_VALUE');

        $this->assertEquals('DEFAULT_VALUE', $resolved);
    }

    public function test_get_or_throw_throws_exception_when_missing()
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Razorpay credentials are not configured.');

        CredentialService::getOrThrow('razorpay', 'KEY_NOT_SET');
    }
}
