<?php

namespace Tests\Feature;

use App\Actions\Estoque\AssociarMaterialAoItemTakeOff;
use App\Actions\Suprimentos\AlocarRequisicaoAoPacote;
use App\Actions\Suprimentos\AtualizarRascunhoPedidoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoCompra;
use App\Actions\Suprimentos\AtualizarRascunhoRequisicaoPlanejamento;
use App\Actions\Suprimentos\CriarPedidoCompra;
use App\Actions\Suprimentos\CriarRequisicaoCompra;
use App\Actions\Suprimentos\CriarRequisicaoPlanejamento;
use App\Actions\Suprimentos\EmitirPedidoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoPlanejamento;
use App\Actions\Suprimentos\RegistrarRecebimentoPedido;
use App\Enums\ModoRastreabilidadeMaterial;
use App\Enums\Papel;
use App\Exceptions\AssociacaoMaterialInvalidaException;
use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\Fornecedor;
use App\Models\ItemSuprimento;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\Material;
use App\Models\PedidoCompraItem;
use App\Models\RecebimentoPedido;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 20, Etapa 20.9.CORREÇÃO — fecha exclusivamente o Achado C1 da
 * Auditoria Integrada 20.9: escrita cross-obra no fluxo de Associação
 * Material ↔ ItemTakeOff. Cobertura A-K do pedido de correção.
 */
class EstoqueAssociarMaterialObraCorrecaoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obraA;
    private Work $obraB;
    private UnidadeMedida $unidade;
    private AssociarMaterialAoItemTakeOff $associarMaterial;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-12-15'));

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obraA = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obraB = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obraA, $this->user, Papel::GerentePlanejamento->value);
        $this->vincularObra($this->obraB, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);
        $this->unidade = UnidadeMedida::create(['tenant_id' => $this->tenant->id, 'codigo' => 'M', 'nome' => 'Metro']);

        $this->associarMaterial = new AssociarMaterialAoItemTakeOff();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- helpers (mesma toolkit de EstoqueFundacaoCorrecaoTest, parametrizada por obra) ----

    private function criarMaterial(array $overrides = []): Material
    {
        return Material::create(array_merge([
            'codigo' => 'MAT-' . uniqid(), 'descricao' => 'Material de Teste',
            'unidade_medida_id' => $this->unidade->id,
            'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value, 'ativo' => true,
        ], $overrides));
    }

    private function criarItemTakeOffOrfao(?Material $material, Work $obra): ItemTakeOff
    {
        $doc = DocumentoEngenharia::create(['obra_id' => $obra->id, 'codigo' => 'D' . uniqid(), 'descricao' => 'D']);
        $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM' . uniqid()]);

        return ItemTakeOff::create([
            'lista_engenharia_id' => $lista->id, 'codigo' => 'A' . uniqid(), 'descricao' => 'Item',
            'quantidade' => 1000, 'material_id' => $material?->id,
        ]);
    }

    private function criarRpItemEmitido(ItemTakeOff $item, float $quantidade, Work $obra): \App\Models\RequisicaoPlanejamentoItem
    {
        $rp = (new CriarRequisicaoPlanejamento())->execute($obra->id, null, $this->user->id);
        $rpItem = (new AtualizarRascunhoRequisicaoPlanejamento())->adicionarItem($rp, $item->id, $quantidade);
        (new EmitirRequisicaoPlanejamento())->execute($rp->fresh(), $this->user);

        return $rpItem->fresh();
    }

    /** Cadeia completa até Recebimento físico, pra provar o vetor real da UI (recebimentoId manipulado). */
    private function criarRecebimentoPendente(ItemTakeOff $item, float $quantidade, Work $obra): RecebimentoPedido
    {
        $rpItem = $this->criarRpItemEmitido($item, $quantidade, $obra);
        $pacote = ItemSuprimento::create(['obra_id' => $obra->id, 'nome' => 'Pacote ' . uniqid()]);
        $alocacao = (new AlocarRequisicaoAoPacote())->alocar($rpItem, $pacote, $quantidade);

        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo ' . uniqid()]);
        $fluxo->etapas()->create(['tenant_id' => $this->tenant->id, 'ordem' => 1, 'nome' => 'Cotação', 'prazo_dias_uteis' => 1]);
        $rc = (new CriarRequisicaoCompra())->execute($alocacao->pacote, $fluxo->fresh(['etapas']), null, $this->user);
        (new AtualizarRascunhoRequisicaoCompra())->adicionarItem($rc, $alocacao, $quantidade);
        $rcEmitida = (new EmitirRequisicaoCompra())->execute($rc->fresh(), $this->user);

        $fornecedor = Fornecedor::create(['obra_id' => $obra->id, 'nome' => 'Fornecedor ' . uniqid()]);
        $pedido = (new CriarPedidoCompra())->execute($rcEmitida, $fornecedor, '2026-12-01', null, null, null, $this->user);
        $pedidoItem = (new AtualizarRascunhoPedidoCompra())->adicionarItem($pedido, $rcEmitida->itens->first(), $quantidade)->fresh();
        (new EmitirPedidoCompra())->execute($pedido->fresh(), $this->user);

        return (new RegistrarRecebimentoPedido())->execute($pedidoItem->fresh(), $quantidade, Carbon::parse('2026-12-10'), $this->user);
    }

    // =========================================================
    // A: mesma obra -> permitida
    // =========================================================

    public function test_a_mesma_obra_associacao_permitida(): void
    {
        $material = $this->criarMaterial();
        $item = $this->criarItemTakeOffOrfao(null, $this->obraA);

        $this->associarMaterial->execute($this->obraA, $item, $material);

        $this->assertSame($material->id, $item->fresh()->material_id);
    }

    // =========================================================
    // B: outro tenant -> bloqueada (Action)
    // =========================================================

    public function test_b_outro_tenant_bloqueada(): void
    {
        // A checagem de tenant já existia desde a 20.1.CORREÇÃO (e
        // continua coberta por EstoqueFundacaoCorrecaoTest::
        // test_c_cross_tenant_bloqueia_associacao) -- reafirmada aqui
        // como parte da matriz completa desta correção, provando que a
        // NOVA validação de obra (que roda ANTES da de tenant no corpo
        // da Action) não enfraqueceu a proteção de tenant já existente.
        $outroTenant = Tenant::factory()->create();
        $materialOutroTenant = null;
        \App\Support\TenantContext::actingAs($outroTenant, function () use (&$materialOutroTenant, $outroTenant) {
            $umOutroTenant = UnidadeMedida::create(['tenant_id' => $outroTenant->id, 'codigo' => 'M-OUT', 'nome' => 'Metro']);
            $materialOutroTenant = Material::create([
                'codigo' => 'MAT-OUT', 'descricao' => 'Outro tenant', 'unidade_medida_id' => $umOutroTenant->id,
                'modo_rastreabilidade' => ModoRastreabilidadeMaterial::Quantitativo->value, 'ativo' => true,
            ]);
        });

        $itemDaObraA = $this->criarItemTakeOffOrfao(null, $this->obraA);

        $this->expectException(AssociacaoMaterialInvalidaException::class);
        $this->expectExceptionMessage('outro tenant');

        $this->associarMaterial->execute($this->obraA, $itemDaObraA, $materialOutroTenant);
    }

    // =========================================================
    // C: mesmo tenant, outra obra -> bloqueada (Action) -- o achado C1 em si
    // =========================================================

    public function test_c_mesmo_tenant_outra_obra_bloqueada_via_action(): void
    {
        $material = $this->criarMaterial();
        $itemDaObraB = $this->criarItemTakeOffOrfao(null, $this->obraB);

        $this->expectException(AssociacaoMaterialInvalidaException::class);
        $this->expectExceptionMessage('não pertence à obra atual');

        // Contexto de operação = obraA, mas o item pertence à obraB.
        $this->associarMaterial->execute($this->obraA, $itemDaObraB, $material);
    }

    // =========================================================
    // D: usuário com acesso às DUAS obras -- continua bloqueada no contexto errado
    // =========================================================

    public function test_d_usuario_com_acesso_as_duas_obras_ainda_bloqueado_no_contexto_errado(): void
    {
        // $this->user já tem vínculo em obraA E obraB (setUp) -- cenário
        // real de usuário multi-obra, não hipotético.
        $material = $this->criarMaterial();
        $itemDaObraB = $this->criarItemTakeOffOrfao(null, $this->obraB);

        try {
            $this->associarMaterial->execute($this->obraA, $itemDaObraB, $material);
            $this->fail('Esperava bloqueio mesmo com o usuário tendo permissão legítima nas duas obras.');
        } catch (AssociacaoMaterialInvalidaException $e) {
            $this->assertTrue(true);
        }

        // No contexto CORRETO (obraB), a mesma operação funciona normalmente.
        $this->associarMaterial->execute($this->obraB, $itemDaObraB, $material);
        $this->assertSame($material->id, $itemDaObraB->fresh()->material_id);
    }

    // =========================================================
    // E: payload Livewire manipulado em abrirModalAssociarMaterial (vetor de LEITURA)
    // =========================================================

    public function test_e_payload_manipulado_em_abrir_modal_bloqueado(): void
    {
        $itemDaObraB = $this->criarItemTakeOffOrfao(null, $this->obraB);
        $recebimentoDaObraB = $this->criarRecebimentoPendente($itemDaObraB, 100, $this->obraB);

        // No contexto da obraA, tenta abrir o modal passando o
        // recebimentoId que na verdade pertence à obraB -- payload manipulado.
        $component = Livewire::test('pages::radar.estoque', ['obra' => $this->obraA])
            ->set('abaAtiva', 'recebimentos');

        $this->expectException(ModelNotFoundException::class);
        $component->call('abrirModalAssociarMaterial', $recebimentoDaObraB->id);
    }

    public function test_e2_payload_manipulado_nunca_preenche_estado_do_modal(): void
    {
        $itemDaObraB = $this->criarItemTakeOffOrfao(null, $this->obraB);
        $recebimentoDaObraB = $this->criarRecebimentoPendente($itemDaObraB, 100, $this->obraB);

        $component = Livewire::test('pages::radar.estoque', ['obra' => $this->obraA])
            ->set('abaAtiva', 'recebimentos');

        try {
            $component->call('abrirModalAssociarMaterial', $recebimentoDaObraB->id);
        } catch (ModelNotFoundException $e) {
            // esperado
        }

        $this->assertNull($component->get('itemTakeOffAssociarId'), 'itemTakeOffAssociarId nunca deveria ser preenchido com um item de outra obra');
        $this->assertFalse($component->get('modalAssociarAberto'));
    }

    // =========================================================
    // F: tentativa de confirmação manipulada -- itemTakeOffAssociarId setado
    //    DIRETO via payload, sem passar por abrirModalAssociarMaterial (vetor de ESCRITA)
    // =========================================================

    public function test_f_confirmacao_manipulada_via_propriedade_publica_bloqueada(): void
    {
        $material = $this->criarMaterial();
        $itemDaObraB = $this->criarItemTakeOffOrfao(null, $this->obraB);

        // Nunca chama abrirModalAssociarMaterial -- seta a propriedade
        // pública diretamente, simulando um payload Livewire forjado.
        Livewire::test('pages::radar.estoque', ['obra' => $this->obraA])
            ->set('itemTakeOffAssociarId', $itemDaObraB->id)
            ->set('materialSelecionadoId', $material->id)
            ->call('confirmarAssociarMaterial')
            ->assertHasErrors('associarGeral');

        $this->assertNull($itemDaObraB->fresh()->material_id, 'ACHADO: confirmarAssociarMaterial() permitiu escrita cross-obra via propriedade pública manipulada');
    }

    // =========================================================
    // G: chamada direta da Action (fora da UI) -- cross-obra
    // =========================================================

    public function test_g_chamada_direta_da_action_cross_obra_bloqueada(): void
    {
        $material = $this->criarMaterial();
        $itemDaObraB = $this->criarItemTakeOffOrfao(null, $this->obraB);

        try {
            (new AssociarMaterialAoItemTakeOff())->execute($this->obraA, $itemDaObraB, $material);
            $this->fail('Chamada direta da Action deveria bloquear cross-obra independente de qualquer camada de UI.');
        } catch (AssociacaoMaterialInvalidaException $e) {
            $this->assertTrue(true);
        }
    }

    // =========================================================
    // H: falha não altera material_id (zero write em toda tentativa bloqueada)
    // =========================================================

    public function test_h_falha_nunca_altera_material_id(): void
    {
        $materialOriginal = $this->criarMaterial(['codigo' => 'ORIGINAL']);
        $materialNovo = $this->criarMaterial(['codigo' => 'NOVO']);
        $itemDaObraB = $this->criarItemTakeOffOrfao($materialOriginal, $this->obraB);

        try {
            $this->associarMaterial->execute($this->obraA, $itemDaObraB, $materialNovo);
        } catch (AssociacaoMaterialInvalidaException $e) {
            // esperado
        }

        $this->assertSame($materialOriginal->id, $itemDaObraB->fresh()->material_id, 'material_id nunca deveria mudar após uma tentativa bloqueada');
    }

    // =========================================================
    // I: associação legítima existente continua funcionando (não regrediu)
    // =========================================================

    public function test_i_fluxo_completo_legitimo_continua_funcionando_via_ui(): void
    {
        $material = $this->criarMaterial(['codigo' => 'UI-LEGIT']);
        $item = $this->criarItemTakeOffOrfao(null, $this->obraA);
        $recebimento = $this->criarRecebimentoPendente($item, 100, $this->obraA);

        Livewire::test('pages::radar.estoque', ['obra' => $this->obraA])
            ->set('abaAtiva', 'recebimentos')
            ->call('abrirModalAssociarMaterial', $recebimento->id)
            ->set('materialSelecionadoId', $material->id)
            ->call('confirmarAssociarMaterial')
            ->assertHasNoErrors('associarGeral')
            ->assertSet('modalAssociarAberto', false);

        $this->assertSame($material->id, $item->fresh()->material_id);
    }

    // =========================================================
    // J: isolamento de tenant/obra não sofre regressão (checagem local, TenantIsolationTest roda à parte)
    // =========================================================

    public function test_j_isolamento_de_obra_nao_impede_operacao_legitima_em_qualquer_uma_das_duas(): void
    {
        $materialA = $this->criarMaterial();
        $materialB = $this->criarMaterial();
        $itemA = $this->criarItemTakeOffOrfao(null, $this->obraA);
        $itemB = $this->criarItemTakeOffOrfao(null, $this->obraB);

        $this->associarMaterial->execute($this->obraA, $itemA, $materialA);
        $this->associarMaterial->execute($this->obraB, $itemB, $materialB);

        $this->assertSame($materialA->id, $itemA->fresh()->material_id);
        $this->assertSame($materialB->id, $itemB->fresh()->material_id);
    }

    // =========================================================
    // K: prova de escrita (não só erro de UI) -- reprodução exata do achado da auditoria
    // =========================================================

    public function test_k_prova_zero_write_cross_obra_reproducao_exata_da_auditoria(): void
    {
        $materialDaObraA = $this->criarMaterial();
        $itemDaObraB = $this->criarItemTakeOffOrfao(null, $this->obraB);
        $recebimentoDaObraB = $this->criarRecebimentoPendente($itemDaObraB, 100, $this->obraB);

        $component = Livewire::test('pages::radar.estoque', ['obra' => $this->obraA]);

        try {
            $component->call('abrirModalAssociarMaterial', $recebimentoDaObraB->id);
            $component->set('materialSelecionadoId', $materialDaObraA->id)->call('confirmarAssociarMaterial');
        } catch (ModelNotFoundException $e) {
            // comportamento correto -- bloqueado na leitura, nunca chega na escrita
        }

        $this->assertNull(
            $itemDaObraB->fresh()->material_id,
            'ACHADO C1 REGREDIU: item de outra obra foi escrito com Material da obra atual.'
        );
    }
}
