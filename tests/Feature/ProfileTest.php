<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed(): void
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
    }

    public function test_profile_page_shows_cargo_e_telefone(): void
    {
        $user = User::factory()->create([
            'cargo' => 'Engenheiro de Planejamento',
            'telefone' => '(11) 91234-5678',
        ]);

        $response = $this
            ->actingAs($user)
            ->get('/profile');

        $response->assertOk();
        $response->assertSee('Engenheiro de Planejamento');
        $response->assertSee('(11) 91234-5678');
    }

    public function test_guest_cannot_access_profile_page(): void
    {
        $response = $this->get('/profile');

        $response->assertRedirect(route('login'));
    }
}
