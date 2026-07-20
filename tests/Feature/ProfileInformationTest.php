<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Jetstream\Http\Livewire\UpdateProfileInformationForm;
use Livewire\Livewire;
use Tests\TestCase;

class ProfileInformationTest extends TestCase
{
    use RefreshDatabase;

    public function test_current_profile_information_is_available(): void
    {
        $this->actingAs($user = User::factory()->create([
            'cargo' => 'Engenheiro de Planejamento',
            'telefone' => '(11) 91234-5678',
        ]));

        $component = Livewire::test(UpdateProfileInformationForm::class);

        $this->assertEquals($user->first_name, $component->state['first_name']);
        $this->assertEquals($user->last_name, $component->state['last_name']);
        $this->assertEquals($user->email, $component->state['email']);
        $this->assertEquals('Engenheiro de Planejamento', $component->state['cargo']);
        $this->assertEquals('(11) 91234-5678', $component->state['telefone']);
    }

    public function test_profile_information_can_be_updated(): void
    {
        $this->actingAs($user = User::factory()->create());

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('state', ['first_name' => 'Test', 'last_name' => 'Name', 'email' => 'test@example.com'])
            ->call('updateProfileInformation');

        $this->assertEquals('Test', $user->fresh()->first_name);
        $this->assertEquals('Name', $user->fresh()->last_name);
        $this->assertEquals('test@example.com', $user->fresh()->email);
    }

    public function test_cargo_e_telefone_podem_ser_atualizados(): void
    {
        $this->actingAs($user = User::factory()->create());

        Livewire::test(UpdateProfileInformationForm::class)
            ->set('state', [
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'cargo' => 'Diretor de Obras',
                'telefone' => '(21) 99876-5432',
            ])
            ->call('updateProfileInformation');

        $this->assertEquals('Diretor de Obras', $user->fresh()->cargo);
        $this->assertEquals('(21) 99876-5432', $user->fresh()->telefone);
    }
}
