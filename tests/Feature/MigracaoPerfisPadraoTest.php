<?php

namespace Tests\Feature;

use App\Models\Perfil;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ao nascer, um tenant precisa ganhar os 5 perfis padrão com as
 * permissões EXATAS que reproduzem o comportamento do antigo Papel fixo
 * (App\Models\Perfil::REGRAS_ESCRITA) — ninguém pode perder acesso na
 * migração pro sistema de perfis dinâmicos. Testa os casos de fronteira
 * da hierarquia antiga: Cliente Leitura só via, Encarregado não exclui
 * nada, Admin tem tudo.
 */
class MigracaoPerfisPadraoTest extends TestCase
{
    use RefreshDatabase;

    private function temPermissao(Perfil $perfil, string $funcionalidade, string $acao): bool
    {
        return $perfil->permissoes()
            ->where('funcionalidade', $funcionalidade)
            ->where('acao', $acao)
            ->exists();
    }

    public function test_tenant_novo_nasce_com_os_5_perfis_padrao(): void
    {
        $tenant = Tenant::factory()->create();

        $slugs = Perfil::where('tenant_id', $tenant->id)->pluck('slug_padrao')->sort()->values()->all();

        $this->assertEquals(
            ['admin', 'cliente_leitura', 'encarregado', 'engenheiro', 'gerente_planejamento'],
            $slugs
        );
    }

    public function test_cliente_leitura_so_tem_ver_em_tudo_nunca_escrita(): void
    {
        $tenant = Tenant::factory()->create();
        $cliente = Perfil::porSlugPadrao($tenant, 'cliente_leitura');

        $this->assertTrue($this->temPermissao($cliente, 'restricoes.quadro', 'ver'));
        $this->assertTrue($this->temPermissao($cliente, 'report.relatorios', 'ver'));
        $this->assertFalse($this->temPermissao($cliente, 'restricoes.quadro', 'criar'));
        $this->assertFalse($this->temPermissao($cliente, 'restricoes.quadro', 'editar'));
        $this->assertFalse($this->temPermissao($cliente, 'restricoes.quadro', 'excluir'));
        $this->assertFalse($this->temPermissao($cliente, 'cadastros.categorias_restricao', 'criar'));
    }

    public function test_encarregado_nao_tem_nenhuma_permissao_de_excluir(): void
    {
        $tenant = Tenant::factory()->create();
        $encarregado = Perfil::porSlugPadrao($tenant, 'encarregado');

        $this->assertFalse($encarregado->permissoes()->where('acao', 'excluir')->exists());
        $this->assertTrue($this->temPermissao($encarregado, 'restricoes.quadro', 'criar'));
        $this->assertTrue($this->temPermissao($encarregado, 'restricoes.lookahead', 'editar'));
    }

    public function test_engenheiro_edita_restricoes_mas_nao_exclui_nem_gerencia_obra(): void
    {
        $tenant = Tenant::factory()->create();
        $engenheiro = Perfil::porSlugPadrao($tenant, 'engenheiro');

        $this->assertTrue($this->temPermissao($engenheiro, 'restricoes.quadro', 'editar'));
        $this->assertTrue($this->temPermissao($engenheiro, 'restricoes.lookahead', 'criar'));
        $this->assertFalse($this->temPermissao($engenheiro, 'restricoes.quadro', 'excluir'));
        $this->assertFalse($this->temPermissao($engenheiro, 'obras.minhas_obras', 'editar'));
    }

    public function test_gerente_planejamento_gerencia_obra_e_report_mas_nao_cadastros_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $gerente = Perfil::porSlugPadrao($tenant, 'gerente_planejamento');

        $this->assertTrue($this->temPermissao($gerente, 'obras.minhas_obras', 'editar'));
        $this->assertTrue($this->temPermissao($gerente, 'report.relatorios', 'criar'));
        $this->assertTrue($this->temPermissao($gerente, 'report.relatorios', 'excluir'));
        $this->assertFalse($this->temPermissao($gerente, 'obras.minhas_obras', 'excluir'));
        $this->assertFalse($this->temPermissao($gerente, 'cadastros.categorias_restricao', 'criar'));
    }

    public function test_admin_tem_todas_as_permissoes_do_catalogo(): void
    {
        $tenant = Tenant::factory()->create();
        $admin = Perfil::porSlugPadrao($tenant, 'admin');

        $this->assertTrue($this->temPermissao($admin, 'obras.minhas_obras', 'excluir'));
        $this->assertTrue($this->temPermissao($admin, 'cadastros.categorias_restricao', 'criar'));
        $this->assertTrue($this->temPermissao($admin, 'cadastros.categorias_restricao', 'editar'));
        $this->assertTrue($this->temPermissao($admin, 'cadastros.categorias_restricao', 'excluir'));
        $this->assertTrue($this->temPermissao($admin, 'report.relatorios', 'excluir'));
    }
}
