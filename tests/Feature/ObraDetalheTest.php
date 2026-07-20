<?php

namespace Tests\Feature;

use App\Enums\OrigemAtividade;
use App\Enums\Papel;
use App\Enums\StatusAtividade;
use App\Enums\StatusRestricao;
use App\Models\Atividade;
use App\Models\Client;
use App\Models\CronogramaImportacao;
use App\Models\PacoteTrabalho;
use App\Models\Perfil;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ObraDetalheTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);
    }

    private function componente()
    {
        return Livewire::test('pages::gestao.obra-detalhe', ['obra' => $this->obra]);
    }

    public function test_pagina_carrega_para_usuario_com_acesso(): void
    {
        $this->get(route('gestao.obra.show', $this->obra))
            ->assertOk()
            ->assertSeeLivewire('pages::gestao.obra-detalhe');
    }

    public function test_usuario_sem_acesso_a_obra_recebe_forbidden(): void
    {
        $outroTenant = Tenant::factory()->create();
        $outraObra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
        $semAcesso = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($semAcesso);

        // Navegação de página cheia sem acesso não mostra mais 403 cru —
        // App\Exceptions\Handler::render() redireciona com flash.popup.
        $this->get(route('gestao.obra.show', $this->obra))
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');
    }

    public function test_kpis_da_visao_geral_batem_com_os_dados(): void
    {
        $pronta = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
        ]);
        $comRestricao = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
        ]);
        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $comRestricao->id,
            'bloqueante' => true,
            'status' => StatusRestricao::Aberta->value,
        ]);
        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => true,
        ]);

        $componente = $this->componente();

        $this->assertEquals(2, $componente->instance()->totalAtividades);
        $this->assertEquals(1, $componente->instance()->atividadesProntas);
        $this->assertEquals(1, $componente->instance()->restricoesAbertas);
        $this->assertEquals(1, $componente->instance()->restricoesBloqueantes);
    }

    public function test_editar_dados_da_obra_atualiza_o_registro(): void
    {
        $novoCliente = Client::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->componente()
            ->call('abrirEdicaoDados')
            ->set('nomeEdit', 'Obra Renomeada')
            ->set('clienteIdEdit', $novoCliente->id)
            ->set('localizacaoEdit', 'Recife, PE')
            ->set('statusEdit', 'em_andamento')
            ->set('diaSemanaReportEdit', '3')
            ->call('salvarDadosObra');

        $this->obra->refresh();
        $this->assertEquals('Obra Renomeada', $this->obra->name);
        $this->assertEquals($novoCliente->id, $this->obra->client_id);
        $this->assertEquals('Recife, PE', $this->obra->location);
        $this->assertEquals('em_andamento', $this->obra->status);
        $this->assertSame(3, $this->obra->dia_semana_report);
    }

    public function test_desligar_relatorio_automatico_grava_null(): void
    {
        $this->obra->update(['dia_semana_report' => 2]);

        $this->componente()
            ->call('abrirEdicaoDados')
            ->assertSet('diaSemanaReportEdit', '2')
            ->set('diaSemanaReportEdit', '')
            ->call('salvarDadosObra');

        $this->assertNull($this->obra->refresh()->dia_semana_report);
    }

    public function test_usuario_sem_permissao_nao_pode_editar_dados_da_obra(): void
    {
        $encarregado = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $encarregado, Papel::Encarregado->value);
        $this->actingAs($encarregado);

        Livewire::test('pages::gestao.obra-detalhe', ['obra' => $this->obra])
            ->call('abrirEdicaoDados')
            ->assertForbidden();
    }

    public function test_adicionar_alterar_e_remover_membro_da_equipe(): void
    {
        $novoMembro = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $engenheiro = Perfil::porSlugPadrao($this->tenant, 'engenheiro');
        $encarregado = Perfil::porSlugPadrao($this->tenant, 'encarregado');

        $componente = $this->componente()
            ->set('perfilNovoMembroId', $engenheiro->id)
            ->call('adicionarMembro', $novoMembro->id);

        $this->assertEquals(
            $engenheiro->id,
            $this->obra->users()->where('user_id', $novoMembro->id)->first()->pivot->perfil_id
        );

        $componente->call('alterarPerfil', $novoMembro->id, $encarregado->id);
        $this->assertEquals(
            $encarregado->id,
            $this->obra->users()->where('user_id', $novoMembro->id)->first()->pivot->perfil_id
        );

        $componente->call('removerMembro', $novoMembro->id);
        $this->assertFalse($this->obra->users()->where('user_id', $novoMembro->id)->exists());
    }

    public function test_busca_de_usuarios_para_adicionar_filtra_por_nome(): void
    {
        $achado = User::factory()->create(['tenant_id' => $this->tenant->id, 'first_name' => 'Ricardo', 'last_name' => 'Alves']);
        $naoAchado = User::factory()->create(['tenant_id' => $this->tenant->id, 'first_name' => 'Marcos', 'last_name' => 'Souza']);

        $nomes = $this->componente()
            ->set('buscaUsuario', 'Ricardo')
            ->instance()->usuariosParaAdicionar
            ->pluck('first_name');

        $this->assertTrue($nomes->contains('Ricardo'));
        $this->assertFalse($nomes->contains('Marcos'));
    }

    public function test_usuario_sem_permissao_nao_pode_gerenciar_equipe(): void
    {
        $encarregado = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $encarregado, Papel::Encarregado->value);
        $this->actingAs($encarregado);

        $outroUsuario = User::factory()->create(['tenant_id' => $this->tenant->id]);

        Livewire::test('pages::gestao.obra-detalhe', ['obra' => $this->obra])
            ->call('adicionarMembro', $outroUsuario->id)
            ->assertForbidden();
    }

    public function test_resumo_do_cronograma_conta_pacotes_e_atividades(): void
    {
        PacoteTrabalho::factory()->count(2)->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
        ]);
        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
            'baseline_inicio' => now(),
        ]);
        Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
            'baseline_inicio' => null,
        ]);

        $resumo = $this->componente()->instance()->resumoCronograma;

        $this->assertEquals(2, $resumo['totalPacotes']);
        $this->assertEquals(2, $resumo['totalAtividades']);
        $this->assertEquals(1, $resumo['comBaseline']);
    }

    public function test_historico_de_importacoes_lista_as_importacoes_da_obra(): void
    {
        CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'user_id' => $this->user->id,
            'arquivo' => 'cronograma-v1.xml',
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'criadas' => 10,
            'atualizadas' => 0,
            'removidas' => 0,
            'importado_em' => now()->subDays(2),
        ]);
        CronogramaImportacao::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'user_id' => $this->user->id,
            'arquivo' => 'cronograma-v2.xml',
            'metodo_distribuicao' => 'ponto_medio_recurso_trabalho',
            'criadas' => 0,
            'atualizadas' => 8,
            'removidas' => 2,
            'importado_em' => now(),
        ]);

        $importacoes = $this->componente()->instance()->importacoes;

        $this->assertEquals(2, $importacoes->count());
        $this->assertEquals('cronograma-v2.xml', $importacoes->first()->arquivo);
    }
}
