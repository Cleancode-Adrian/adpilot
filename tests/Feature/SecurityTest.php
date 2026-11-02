<?php

namespace Tests\Feature;

use App\Mail\SecurityAlertMail;
use App\Models\Client;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Services\Security\DeviceGuard;
use App\Services\Security\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private function userWith2fa(bool $admin = true): array
    {
        $secret = Totp::generateSecret();
        $user = User::factory()->create(['is_admin' => $admin]);
        $user->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
        $codes = $user->regenerateRecoveryCodes();

        return [$user, $secret, $codes];
    }

    private function codeAt(string $secret, int $offsetSteps = 0): string
    {
        return Totp::code($secret, intdiv(time(), 30) + $offsetSteps);
    }

    public function test_totp_matches_rfc6238_vectors(): void
    {
        $s = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // "12345678901234567890"
        $this->assertSame('287082', Totp::code($s, intdiv(59, 30)));
        $this->assertSame('005924', Totp::code($s, intdiv(1234567890, 30)));
        $this->assertSame('279037', Totp::code($s, intdiv(2000000000, 30)));
        $this->assertStringStartsWith('otpauth://totp/', Totp::uri($s, 'a@b.pl', 'Panel'));
        $this->assertStringContainsString('<svg', Totp::qrSvg(Totp::uri($s, 'a@b.pl', 'Panel')));
    }

    public function test_two_factor_setup_flow(): void
    {
        Mail::fake();
        $user = User::factory()->create(['is_admin' => true]);
        $this->actingAs($user);

        // włączenie wymaga świeżego potwierdzenia hasła
        $this->post('/settings/security/two-factor')->assertRedirect(route('password.confirm'));
        $this->withSession(['auth.password_confirmed_at' => time()])->post('/settings/security/two-factor')->assertRedirect();
        $secret = session('two_factor.setup_secret');
        $this->assertNotEmpty($secret);
        $this->get('/settings/security')->assertInertia(fn ($p) => $p->component('settings/security')->where('twoFactor.enabled', false)->has('twoFactor.setup.qr'));

        $this->post('/settings/security/two-factor/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->post('/settings/security/two-factor/confirm', ['code' => $this->codeAt($secret)])->assertSessionHas('two_factor.recovery_codes');

        $user->refresh();
        $this->assertTrue($user->two_factor_enabled);
        $this->assertCount(10, $user->two_factor_recovery_codes);
        $this->assertStringNotContainsString($secret, (string) DB::table('users')->where('id', $user->id)->value('two_factor_secret'), 'sekret szyfrowany');
        $this->assertTrue(SecurityEvent::where('type', 'two_factor_enabled')->exists());
        Mail::assertSent(SecurityAlertMail::class);

        // admin nie może wyłączyć, gdy 2FA jest wymagane
        config(['adpilot.security.require_2fa' => true]);
        $this->withSession(['auth.password_confirmed_at' => time()])->delete('/settings/security/two-factor')->assertSessionHas('error');
        $this->assertTrue($user->fresh()->two_factor_enabled);
    }

    public function test_login_requires_code_and_codes_cannot_be_reused(): void
    {
        [$user, $secret] = $this->userWith2fa();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        $this->get('/clients')->assertRedirect('/login');

        $this->post('/two-factor-challenge', ['code' => '123456'])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->assertTrue(SecurityEvent::where('type', 'two_factor_failed')->exists());

        $code = $this->codeAt($secret);
        $this->post('/two-factor-challenge', ['code' => $code])->assertRedirect('/clients');
        $this->assertAuthenticatedAs($user);

        // ten sam kod drugi raz nie zadziała (ochrona przed podsłuchaniem)
        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/two-factor-challenge', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_recovery_code_works_once(): void
    {
        Mail::fake();
        [$user, , $codes] = $this->userWith2fa();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/two-factor-challenge', ['recovery_code' => strtolower($codes[0])])->assertRedirect('/clients');
        $this->assertAuthenticatedAs($user);
        $this->assertCount(9, $user->fresh()->two_factor_recovery_codes);

        $this->post('/logout');
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->post('/two-factor-challenge', ['recovery_code' => $codes[0]])->assertSessionHasErrors('recovery_code');
        $this->assertGuest();
    }

    public function test_challenge_expires_and_is_rate_limited(): void
    {
        [$user, $secret] = $this->userWith2fa();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $this->travel(6)->minutes();
        $this->post('/two-factor-challenge', ['code' => $this->codeAt($secret)])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->travelBack();

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        foreach (range(1, 5) as $i) {
            $this->post('/two-factor-challenge', ['code' => '000000']);
        }
        $this->post('/two-factor-challenge', ['code' => $this->codeAt($secret)])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_admin_without_2fa_is_sent_to_security_settings(): void
    {
        config(['adpilot.security.require_2fa' => true]);
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin);

        $this->get('/clients')->assertRedirect(route('security.edit'));
        $this->get('/admin/status')->assertRedirect(route('security.edit'));
        $this->get('/settings/security')->assertOk();

        // zwykły użytkownik – bez wymogu
        $this->actingAs(User::factory()->create(['is_admin' => false]));
        $this->get('/clients')->assertOk();
    }

    public function test_vault_unlock_requires_password_and_code_and_expires(): void
    {
        [$user, $secret] = $this->userWith2fa();
        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()]);
        $client = Client::create(['name' => 'K', 'website_url' => 'https://k.example', 'currency' => 'PLN']);
        $this->post("/clients/{$client->id}/credentials", ['category' => 'cms', 'label' => 'WP', 'username' => 'admin', 'secret' => 'Tajne1!']);
        $cred = $client->credentials()->first();

        $this->postJson("/credentials/{$cred->id}/reveal")->assertStatus(423);
        $this->postJson('/vault/unlock', ['password' => 'zle', 'code' => $this->codeAt($secret)])->assertStatus(422)->assertJsonPath('errors.password', 'Nieprawidłowe hasło.');
        $this->postJson('/vault/unlock', ['password' => 'password', 'code' => '000000'])->assertStatus(422)->assertJsonPath('errors.code', 'Nieprawidłowy kod z aplikacji.');
        $this->postJson('/vault/unlock', ['password' => 'password', 'code' => $this->codeAt($secret)])->assertOk()->assertJsonStructure(['unlocked_until']);

        $this->postJson("/credentials/{$cred->id}/reveal")->assertOk()->assertJson(['secret' => 'Tajne1!']);
        $this->assertTrue(SecurityEvent::where('type', 'vault_unlocked')->exists());

        $this->travel(config('adpilot.security.vault_unlock_seconds') + 5)->seconds();
        $this->postJson("/credentials/{$cred->id}/reveal")->assertStatus(423);
        $this->travelBack();

        $this->postJson('/vault/lock')->assertOk();
        $this->postJson("/credentials/{$cred->id}/reveal")->assertStatus(423);
    }

    public function test_new_device_login_sends_alert_but_first_login_does_not(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $first = $this->post('/login', ['email' => $user->email, 'password' => 'password']);
        $first->assertCookie(DeviceGuard::COOKIE);
        Mail::assertNothingSent();
        $token = $first->getCookie(DeviceGuard::COOKIE, false)->getValue();
        $this->post('/logout');

        // ta sama przeglądarka – bez alertu
        $this->withUnencryptedCookie(DeviceGuard::COOKIE, $token)->post('/login', ['email' => $user->email, 'password' => 'password']);
        Mail::assertNothingSent();
        $this->post('/logout');

        // nowa przeglądarka – alert e-mail i wpis w dzienniku
        $this->withUnencryptedCookie(DeviceGuard::COOKIE, 'inna')->post('/login', ['email' => $user->email, 'password' => 'password']);
        Mail::assertSent(SecurityAlertMail::class, fn ($m) => $m->title === 'Logowanie z nowego urządzenia' && $m->hasTo($user->email));
        $this->assertTrue(SecurityEvent::where('type', 'login_new_device')->exists());
        $this->assertSame(2, $user->devices()->count());
    }

    public function test_failed_logins_are_logged_and_lockout_notifies_owner(): void
    {
        Mail::fake();
        $user = User::factory()->create();
        foreach (range(1, 5) as $i) {
            $this->post('/login', ['email' => $user->email, 'password' => 'zle-haslo']);
        }
        $this->assertSame(5, SecurityEvent::where('type', 'login_failed')->count());

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest(); // poprawne hasło też blokowane podczas blokady
        $this->assertTrue(SecurityEvent::where('type', 'login_locked')->exists());
        Mail::assertSent(SecurityAlertMail::class, fn ($m) => $m->title === 'Wiele nieudanych prób logowania');
    }

    public function test_weak_password_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->put('/settings/password', ['current_password' => 'password', 'password' => 'haslo123', 'password_confirmation' => 'haslo123'])
            ->assertSessionHasErrors('password');
    }

    public function test_security_headers_and_csp_nonce(): void
    {
        config(['adpilot.security.csp' => 'enforce']);
        $res = $this->get('/login')->assertOk();
        $res->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff');
        $csp = $res->headers->get('Content-Security-Policy');
        $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-([A-Za-z0-9]+)'/", $csp);
        preg_match("/'nonce-([A-Za-z0-9]+)'/", $csp, $m);
        // każdy znacznik <script> na stronie ma nonce
        preg_match_all('/<script\b[^>]*>/i', $res->getContent(), $scripts);
        $this->assertNotEmpty($scripts[0]);
        foreach ($scripts[0] as $tag) {
            $this->assertStringContainsString('nonce="'.$m[1].'"', $tag);
        }
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);
    }

    public function test_email_change_requires_current_password(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'stary@example.com']);
        $this->actingAs($user)->patch('/settings/profile', ['name' => 'X', 'email' => 'nowy@example.com'])->assertSessionHasErrors('current_password');
        $this->assertSame('stary@example.com', $user->fresh()->email);

        $this->patch('/settings/profile', ['name' => 'X', 'email' => 'nowy@example.com', 'current_password' => 'password'])->assertSessionHasNoErrors();
        $this->assertSame('nowy@example.com', $user->fresh()->email);
        Mail::assertSent(SecurityAlertMail::class, fn ($m) => $m->hasTo('stary@example.com'));
    }
}
