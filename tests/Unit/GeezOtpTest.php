<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\SmsService;
use App\Models\Otp;
use App\Services\EnvService;
use Illuminate\Foundation\Testing\RefreshDatabase;

class GeezOtpTest extends TestCase
{
    protected SmsService $smsService;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Mock EnvService to force Geez mode
        $envMock = $this->createMock(EnvService::class);
        $envMock->method('get')->willReturnMap([
            ['SMS_MODE', '1', '2'], // Force GEEZ
            ['GEEZ_SMS_TOKEN', null, 'mock-token'],
            ['GEEZ_SMS_BASE_URL', null, 'http://mock.url'],
            ['GEEZ_SMS_SHORTCODE_ID', null, '1234'],
            ['AFRO_OPT_LENGTH', '4', '4'],
            ['OTP_TTL_MINUTES', '5', '5'],
        ]);

        $this->smsService = new SmsService($envMock);
    }

    public function test_send_otp_geez_creates_db_record()
    {
        $phone = '251912345678';
        
        // We might need to mock Http for the actual SMS send call inside sendOtpGeez
        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response(['error' => false, 'message' => 'Sent'], 200),
        ]);

        $result = $this->smsService->sendOtpGeez($phone);

        $this->assertEquals('success', $result['status']);
        $this->assertDatabaseHas('otps', [
            'phone' => $phone,
            'code' => $result['code'],
            'provider' => 'geez',
        ]);
    }

    public function test_verify_otp_geez_succeeds()
    {
        $phone = '251912345678';
        $code = '1234';
        
        Otp::create([
            'phone' => $phone,
            'code' => $code,
            'expires_at' => now()->addMinutes(5),
            'provider' => 'geez',
        ]);

        $result = $this->smsService->verifyGeezOtp($phone, $code);

        $this->assertEquals('success', $result['status']);
        $this->assertNotNull(Otp::where('phone', $phone)->first()->verified_at);
    }

    public function test_verify_otp_geez_fails_if_expired()
    {
        $phone = '251912345678';
        $code = '1234';
        
        Otp::create([
            'phone' => $phone,
            'code' => $code,
            'expires_at' => now()->subMinutes(1),
            'provider' => 'geez',
        ]);

        $result = $this->smsService->verifyGeezOtp($phone, $code);

        $this->assertEquals('error', $result['status']);
    }
}
