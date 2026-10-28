<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsCampus;
use Tests\TestCase;

class AuthDeviceTest extends TestCase
{
    use BuildsCampus, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCampus();
    }

    private function login(string $login, string $uid, string $password = 'password')
    {
        return $this->postJson('/api/v1/auth/login', [
            'login' => $login, 'password' => $password, 'device_uid' => $uid, 'device_model' => 'Pixel 8', 'platform' => 'android',
        ]);
    }

    public function test_first_login_binds_device_and_later_logins_must_use_it(): void
    {
        $e = $this->makeEmployee();
        $e->devices()->delete();

        $this->login($e->user->email, 'hp-pertama-0123456789')->assertOk()
            ->assertJsonStructure(['data' => ['token', 'user' => ['name', 'offices']]]);
        $this->assertSame('hp-pertama-0123456789', $e->fresh()->activeDevice->device_uid);

        $this->login($e->nip, 'hp-pertama-0123456789')->assertOk(); // login dengan NIP
        $this->login($e->user->email, 'hp-kedua-000000000000')->assertForbidden()->assertJsonPath('error.code', 'unknown_device');

        $e->fresh()->resetDevice();
        $this->assertSame(0, $e->user->tokens()->count());
        $this->login($e->user->email, 'hp-kedua-000000000000')->assertOk();
    }

    public function test_one_phone_cannot_be_bound_to_two_accounts(): void
    {
        $a = $this->makeEmployee();
        $b = $this->makeEmployee();
        $b->devices()->delete();

        $this->login($b->user->email, $this->deviceUids[$a->id])->assertForbidden()
            ->assertJsonPath('error.message', 'Perangkat ini sudah terikat ke akun pegawai lain.');
    }

    public function test_wrong_password_uses_standard_error_shape(): void
    {
        $e = $this->makeEmployee();

        $this->login($e->user->email, $this->deviceUids[$e->id], 'salah')->assertStatus(422)
            ->assertJsonPath('error.code', 'validation_failed')
            ->assertJsonPath('error.message', 'Email/NIP atau password salah.');
    }

    public function test_login_is_rate_limited(): void
    {
        $e = $this->makeEmployee();
        foreach (range(1, 5) as $_) {
            $this->login($e->user->email, $this->deviceUids[$e->id], 'salah');
        }
        $this->login($e->user->email, $this->deviceUids[$e->id])->assertStatus(429)->assertJsonPath('error.code', 'too_many_requests');
    }
}
