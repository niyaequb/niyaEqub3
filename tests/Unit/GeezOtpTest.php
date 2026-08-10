<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Services\SmsService;
use App\Models\Otp;
use App\Services\EnvService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

class GeezOtpTest extends TestCase
{
    use RefreshDatabase;

    protected SmsService $smsService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->smsService = $this->createSmsService('2');
    }

    public function test_send_otp_geez_creates_db_record()
    {
        $phone = '251912345678';

        Http::fake([
            '*' => Http::response(['error' => false, 'message' => 'Sent'], 200),
        ]);

        $result = $this->smsService->sendOtpGeez($phone);

        $this->assertEquals('success', $result['status']);
        $this->assertArrayNotHasKey('code', $result);
        $this->assertArrayNotHasKey('data', $result);
        $this->assertArrayHasKey('verificationId', $result);

        $stored = Otp::where('phone', $phone)->first();

        $this->assertNotNull($stored);
        $this->assertEquals('geez', $stored->provider);
        $this->assertNotNull($stored->code);
        $this->assertEquals(4, strlen($stored->code));
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

    public function test_send_otp_afro_does_not_leak_code()
    {
        $this->smsService = $this->createSmsService('1');

        Http::fake([
            '*' => Http::response([
                'acknowledge' => 'success',
                'response' => [
                    'code' => '1234',
                    'verificationId' => 'mock-verification-id',
                ],
            ], 200),
        ]);

        $result = $this->smsService->sendOtpAfro('251912345678');

        $this->assertEquals('success', $result['status']);
        $this->assertSame('mock-verification-id', $result['verificationId']);
        $this->assertArrayNotHasKey('code', $result);
        $this->assertArrayNotHasKey('data', $result);
    }

    public function test_verify_afro_otp_still_works_with_sanitized_response()
    {
        $this->smsService = $this->createSmsService('1');

        Http::fake([
            '*/verify' => Http::response([
                'acknowledge' => 'success',
                'response' => ['verified' => true],
            ], 200),
        ]);

        $result = $this->smsService->verifyAfroOtp('251912345678', 'mock-verification-id', '1234');

        $this->assertEquals('success', $result['status']);
    }

    private function createSmsService(string $mode): SmsService
    {
        $envMock = $this->createMock(EnvService::class);

        $envMock->method('get')->willReturnMap([
            ['AFRO_BASE_URL', 'https://api.afromessage.com/api', 'http://mock.afro.url'],
            ['AFRO_API_KEY', $mode === '1' ? 'mock-afro-key' : null],
            ['AFRO_IDENTIFIER_ID', '123'],
            ['AFRO_SENDER_NAME', 'TestSender'],
            ['AFRO_OPT_LENGTH', 4, '4'],
            ['AFRO_CALLBACK_URL', '', ''],
            ['GEEZ_SMS_BASE_URL', '', $mode === '2' ? 'http://mock.geez.url' : ''],
            ['GEEZ_SMS_TOKEN', $mode === '2' ? 'mock-geez-token' : null],
            ['GEEZ_SMS_SHORTCODE_ID', $mode === '2' ? '1234' : null],
            ['OTP_TTL_MINUTES', 5, '5'],
            ['SMS_MODE', 1, $mode],
        ]);

        return new SmsService($envMock);
    }
}
