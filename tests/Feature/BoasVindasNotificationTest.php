<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\BoasVindasNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class BoasVindasNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_usuario_recebe_boas_vindas_ao_verificar_email(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $this->actingAs($user)->get($verificationUrl);

        Notification::assertSentTo($user, BoasVindasNotification::class);
    }

    public function test_usuario_nao_recebe_boas_vindas_se_hash_invalido(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email_verified_at' => null,
        ]);

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1('email-errado')]
        );

        $this->actingAs($user)->get($verificationUrl);

        Notification::assertNotSentTo($user, BoasVindasNotification::class);
    }

    public function test_usuario_ja_verificado_nao_recebe_boas_vindas_de_novo(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        $verificationUrl = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );

        $this->actingAs($user)->get($verificationUrl);

        Notification::assertNotSentTo($user, BoasVindasNotification::class);
    }
}
