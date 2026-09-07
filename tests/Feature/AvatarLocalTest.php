<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pré-produção, Etapa 2 (seção 6/18) — avatar padrão (usuário sem foto
 * própria) deixou de chamar ui-avatars.com. Prova que a URL nunca mais
 * contém o domínio externo e que o avatar continua funcional (data URI
 * SVG válido, com as mesmas iniciais/cores de antes).
 */
class AvatarLocalTest extends TestCase
{
    use RefreshDatabase;

    public function test_avatar_padrao_nunca_referencia_dominio_externo(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'first_name' => 'Joana', 'last_name' => 'Duarte']);

        $url = $user->profile_photo_url;

        $this->assertStringNotContainsString('ui-avatars.com', $url);
        $this->assertStringNotContainsString('http://', $url);
        $this->assertStringNotContainsString('https://', $url);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $url);
    }

    public function test_avatar_padrao_contem_as_iniciais_corretas(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'first_name' => 'Joana', 'last_name' => 'Duarte']);

        $svg = base64_decode(str_replace('data:image/svg+xml;base64,', '', $user->profile_photo_url));

        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('JD', $svg);
    }

    public function test_avatar_padrao_nunca_lanca_excecao_com_nome_vazio(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'first_name' => '', 'last_name' => '']);

        $url = $user->profile_photo_url;

        $this->assertStringStartsWith('data:image/svg+xml;base64,', $url);
    }
}
