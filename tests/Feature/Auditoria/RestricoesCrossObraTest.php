<?php

namespace Tests\Feature\Auditoria;

use App\Enums\Papel;
use App\Models\Atividade;
use App\Models\Restricao;
use App\Models\RestricaoAcao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Auditoria Pré-Produção A1, TEN-04 — prova adversarial: abrirModalEdicao()
 * nunca pode popular o estado do componente com dados de uma Restrição de
 * outra obra do mesmo tenant antes de autorizar — nem mesmo pra um usuário
 * sem NENHUM acesso a essa outra obra.
 */
class RestricoesCrossObraTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obraA;
    private Work $obraB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obraA = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);

        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        // Permissão SÓ na Obra A — nunca vinculado à Obra B.
        $this->vincularObra($this->obraA, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);
    }

    private function criarRestricaoNaObraB(): Restricao
    {
        $atividadeB = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obraB->id,
            'fora_do_cronograma' => false,
        ]);

        return Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividadeB->id,
            'descricao' => 'Dado sigiloso da Obra B — nunca deveria vazar pra Obra A',
        ]);
    }

    public function test_ten04_abrir_modal_edicao_de_restricao_de_outra_obra_e_negado_sem_popular_estado(): void
    {
        $restricaoB = $this->criarRestricaoNaObraB();

        // AuthorizationException lançada dentro de $this->authorize() é
        // interceptada pelo próprio App\Exceptions\Handler::render() (que
        // deliberadamente pula o redirect de "acesso negado" pra requests
        // com header X-Livewire, deixando o Laravel renderizar a resposta
        // 403 padrão) — o RequestBroker de teste do Livewire, por sua vez,
        // exclui AuthorizationException/HttpException de
        // withoutExceptionHandling(), então ela NUNCA propaga como exceção
        // PHP crua pro harness de teste (ao contrário de ModelNotFoundException,
        // usada no TEN-01/TEN-02) — mesmo padrão já estabelecido em
        // tests/Feature/AcessoNegadoPopupTest.php (->call(...)->assertForbidden()).
        // A própria resposta 403 já é a prova: $this->authorize('update', $r)
        // (TEN-04) roda ANTES de qualquer atribuição de estado do modal em
        // abrirModalEdicao() — uma AuthorizationException aqui aborta o
        // método antes da primeira linha que populava $this->descricaoNova/
        // $this->modalAberto/etc., então nenhuma delas chega a ser tocada.
        // (Testable::instance() retorna null depois de um 403 — o request
        // nunca chega a hidratar o componente de volta — por isso a prova
        // aqui é só a resposta, não uma leitura pós-call do estado.)
        Livewire::test('pages::radar.restricoes', ['obra' => $this->obraA])
            ->call('abrirModalEdicao', $restricaoB->id)
            ->assertForbidden();

        // Confirmação independente, fora do componente: a Restrição da
        // Obra B continua exatamente como estava — nada foi mutado.
        $this->assertSame(
            'Dado sigiloso da Obra B — nunca deveria vazar pra Obra A',
            $restricaoB->fresh()->descricao
        );
    }

    public function test_ten04_abrir_modal_edicao_da_propria_obra_continua_funcionando(): void
    {
        $atividadeA = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obraA->id,
            'fora_do_cronograma' => false,
        ]);
        $restricaoA = Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividadeA->id,
            'descricao' => 'Restrição legítima da Obra A',
        ]);

        $componente = Livewire::test('pages::radar.restricoes', ['obra' => $this->obraA])
            ->call('abrirModalEdicao', $restricaoA->id);

        $this->assertTrue($componente->instance()->modalAberto);
        $this->assertSame('Restrição legítima da Obra A', $componente->instance()->descricaoNova);
    }

    /**
     * Auditoria Pré-Produção A1.1, TEN-05 — mesma família de TEN-04, agora
     * sobre o modal de COMENTÁRIOS (abrirModalComentarios()/restricaoComentada()),
     * cujo #[Computed] fazia find() sem escopo de obra/permissão nenhum antes
     * da correção. Prova adversarial: descrição, histórico de ações e autor
     * identificável de uma Restrição da Obra B nunca chegam a ser expostos
     * a um usuário sem vínculo com a Obra B, nem mesmo dentro da resposta
     * de erro renderizada.
     */
    public function test_ten05_abrir_modal_comentarios_de_restricao_de_outra_obra_e_negado_sem_expor_dados(): void
    {
        $restricaoB = $this->criarRestricaoNaObraB();

        $autorB = User::factory()->create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'AutorSigilosoB',
            'last_name' => 'Sobrenome',
        ]);

        RestricaoAcao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'restricao_id' => $restricaoB->id,
            'autor_id' => $autorB->id,
            'descricao' => 'Comentário sigiloso da Obra B — nunca deveria vazar pra Obra A',
        ]);

        // Mesmo mecanismo já provado em TEN-04: AuthorizationException
        // lançada dentro de abrirModalComentarios() (agora protegida por
        // $this->authorize('view', $restricao), autorizando ANTES de
        // qualquer atribuição de $this->comentandoId) vira 403 padrão do
        // Laravel — nunca propaga como exceção crua pro harness de teste,
        // e Testable::instance() fica null depois (o componente nunca é
        // rehidratado), então a prova é sobre a RESPOSTA, não sobre estado
        // pós-call.
        $response = Livewire::test('pages::radar.restricoes', ['obra' => $this->obraA])
            ->call('abrirModalComentarios', $restricaoB->id)
            ->assertForbidden();

        // Nenhum dado da Restrição B — descrição, comentário, autor —
        // aparece em lugar nenhum da resposta de erro.
        $response
            ->assertDontSee('Dado sigiloso da Obra B — nunca deveria vazar pra Obra A')
            ->assertDontSee('Comentário sigiloso da Obra B — nunca deveria vazar pra Obra A')
            ->assertDontSee('AutorSigilosoB');

        // Confirmação independente, fora do componente: nada foi mutado
        // (nenhum comentário novo, nenhuma ação criada/alterada).
        $this->assertSame(1, $restricaoB->acoes()->count());
    }

    public function test_ten05_abrir_modal_comentarios_da_propria_obra_continua_funcionando(): void
    {
        $atividadeA = Atividade::factory()->create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obraA->id,
            'fora_do_cronograma' => false,
        ]);
        $restricaoA = Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividadeA->id,
            'descricao' => 'Restrição legítima da Obra A',
        ]);

        RestricaoAcao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'restricao_id' => $restricaoA->id,
            'autor_id' => $this->user->id,
            'descricao' => 'Comentário legítimo da Obra A',
        ]);

        $componente = Livewire::test('pages::radar.restricoes', ['obra' => $this->obraA])
            ->call('abrirModalComentarios', $restricaoA->id);

        $this->assertSame($restricaoA->id, $componente->instance()->comentandoId);

        $rc = $componente->instance()->restricaoComentada();
        $this->assertNotNull($rc);
        $this->assertSame('Restrição legítima da Obra A', $rc->descricao);
        $this->assertSame(1, $rc->acoes->count());
        $this->assertSame('Comentário legítimo da Obra A', $rc->acoes->first()->descricao);
    }
}
