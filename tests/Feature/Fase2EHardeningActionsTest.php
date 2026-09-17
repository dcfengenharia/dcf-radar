<?php

namespace Tests\Feature;

use App\Actions\Engenharia\AlterarLiberacaoRevisaoDocumento;
use App\Actions\Estoque\AprovarAjusteInventario;
use App\Actions\Suprimentos\AtualizarAdjudicacaoRequisicaoCompra;
use App\Actions\Suprimentos\CriarAdjudicacaoRequisicaoCompra;
use App\Actions\Suprimentos\EmitirPedidoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoCompra;
use App\Actions\Suprimentos\EmitirRequisicaoPlanejamento;
use App\Enums\Papel;
use App\Enums\StatusAdjudicacaoRequisicaoCompra;
use App\Enums\TipoLocalEstoque;
use App\Models\DocumentoEngenharia;
use App\Models\DocumentoEngenhariaRevisao;
use App\Models\Fornecedor;
use App\Models\InventarioEstoque;
use App\Models\InventarioItem;
use App\Models\ItemSuprimento;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\ObraUserPerfil;
use App\Models\PedidoCompra;
use App\Models\Perfil;
use App\Models\PerfilPermissao;
use App\Models\RequisicaoCompra;
use App\Models\RequisicaoCompraAdjudicacao;
use App\Models\RequisicaoCompraAdjudicacaoItem;
use App\Models\RequisicaoCompraItem;
use App\Models\RequisicaoPlanejamento;
use App\Models\Tenant;
use App\Models\UnidadeMedida;
use App\Models\User;
use App\Models\Work;
use App\Support\AtribuicaoPerfilObra;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FASE 2E / FASE 2E.CORREÇÃO — defesa em profundidade nas Actions
 * críticas humanas.
 *
 * Cobre as 6 Actions endurecidas (Fase 2E original + Fase 2E.CORREÇÃO):
 *
 * - `EmitirRequisicaoPlanejamento` (planejamento.requisicoes|editar)
 * - `EmitirRequisicaoCompra` (suprimentos.mapa|editar)
 * - `EmitirPedidoCompra` (suprimentos.mapa|editar)
 * - `AprovarAjusteInventario` (dupla: estoque.inventario|editar +
 *   estoque.movimentacao|editar)
 * - `AlterarLiberacaoRevisaoDocumento::liberar()/revogar()` —
 *   FASE 2E.CORREÇÃO (engenharia.pacotes|liberar_para_construcao).
 *   A Fase 2E original tinha REVERTIDO uma tentativa anterior por
 *   quebrar fixtures de 6 arquivos de teste — a Fase 2E.CORREÇÃO
 *   reavaliou essa decisão (fixture quebrar nunca é justificativa
 *   suficiente pra deixar uma capacidade crítica sem defesa em
 *   profundidade) e corrigiu os 6 arquivos dando ao ator de fixture um
 *   segundo usuário com autoridade real, nunca promovendo o ator sob
 *   teste. Ver docblock da própria Action.
 * - `AtualizarAdjudicacaoRequisicaoCompra::adicionarItem()`/
 *   `removerItem()` — FASE 2E.CORREÇÃO (suprimentos.mapa|editar). A
 *   adjudicação nasce `Ativa` (nunca `Rascunho`), então compor seus
 *   itens é a própria decisão comercial em curso, mesma gravidade de
 *   `cancelar()`/`CriarAdjudicacaoRequisicaoCompra` — "não recebiam
 *   `User`" deixou de ser aceito como justificativa.
 *
 * `EmitirGrd` foi reavaliada e **permanece caller-checked** (Classe B —
 * operação normal de Engenharia): reaproveita `engenharia.pacotes|editar`,
 * a MESMA capacidade uniforme de toda a árvore GED — nunca teve uma
 * capacidade própria, ao contrário de `liberar_para_construcao`
 * (deliberadamente separada de `editar` desde a Fase 2B). Decisão
 * documentada no relatório final, não uma omissão.
 *
 * Matriz obrigatória (Seções 25-30 do pedido), por Action: chamada
 * direta sem autoridade (negado), chamada direta com autoridade
 * suficiente (autorização passa — prova por EXCLUSÃO de
 * AuthorizationException, nunca exige montar o cenário de negócio
 * inteiro), same-tenant cross-obra (negado), cross-tenant (negado).
 * `AprovarAjusteInventario` e `AlterarLiberacaoRevisaoDocumento` ganham
 * testes extras (dupla autorização / capacidade isolada / multiperfil /
 * zero perfis).
 */
class Fase2EHardeningActionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Tenant $outroTenant;
    private Work $obra;
    private Work $obraIrma;
    private Work $obraOutroTenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->outroTenant = Tenant::factory()->create();

        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obraIrma = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obraOutroTenant = Work::factory()->create(['tenant_id' => $this->outroTenant->id]);
    }

    private function usuarioComPapel(Work $obra, string $papel): User
    {
        $user = User::factory()->create(['tenant_id' => $obra->tenant_id]);
        $this->vincularObra($obra, $user, $papel);

        return $user;
    }

    /**
     * Perfil PERSONALIZADO (nunca um dos 5 papéis padrão, que sempre
     * concedem 'estoque.inventario|editar' e 'estoque.movimentacao|editar'
     * juntos, no MESMO nível hierárquico — Engenheiro) — grava só a
     * capacidade explicitamente pedida via `PerfilPermissao`, provando
     * que a dupla exigência de `AprovarAjusteInventario` é real (uma
     * permissão isolada, mesmo com membership válida na obra, nunca
     * basta sozinha).
     */
    private function usuarioComPermissaoUnica(Work $obra, string $funcionalidade, string $acao): User
    {
        $user = User::factory()->create(['tenant_id' => $obra->tenant_id]);

        $perfil = Perfil::create(['tenant_id' => $obra->tenant_id, 'nome' => 'Perfil de teste — '.$funcionalidade.'|'.$acao]);
        PerfilPermissao::create([
            'tenant_id' => $obra->tenant_id,
            'perfil_id' => $perfil->id,
            'funcionalidade' => $funcionalidade,
            'acao' => $acao,
        ]);

        $obra->users()->attach($user->id, ['perfil_id' => $perfil->id]);
        AtribuicaoPerfilObra::definirPerfilUnico($obra, $user->id, $perfil->id);

        return $user;
    }

    // ------------------------------------------------------------------
    // EmitirRequisicaoPlanejamento
    // ------------------------------------------------------------------

    private function rpVazia(Work $obra): RequisicaoPlanejamento
    {
        return RequisicaoPlanejamento::create([
            'tenant_id' => $obra->tenant_id,
            'obra_id' => $obra->id,
            'status' => 'rascunho',
        ]);
    }

    public function test_emitir_rp_chamada_direta_sem_autoridade_e_negada(): void
    {
        $semAutoridade = $this->usuarioComPapel($this->obra, Papel::Encarregado->value);
        $rp = $this->rpVazia($this->obra);

        $this->expectException(AuthorizationException::class);
        (new EmitirRequisicaoPlanejamento())->execute($rp, $semAutoridade);
    }

    public function test_emitir_rp_chamada_direta_com_autoridade_passa_da_camada_de_autorizacao(): void
    {
        $autorizado = $this->usuarioComPapel($this->obra, Papel::GerentePlanejamento->value);
        $rp = $this->rpVazia($this->obra);

        try {
            (new EmitirRequisicaoPlanejamento())->execute($rp, $autorizado);
            $this->fail('Esperava uma exceção de negócio (RP sem itens) — nunca de autorização.');
        } catch (AuthorizationException $e) {
            $this->fail('Usuário autorizado nunca deveria ser barrado pela camada de autorização: '.$e->getMessage());
        } catch (\App\Exceptions\RequisicaoPlanejamentoEmissaoInvalidaException $e) {
            $this->assertTrue(true);
        }
    }

    public function test_emitir_rp_same_tenant_cross_obra_e_negado(): void
    {
        $autorizadoNaOutraObra = $this->usuarioComPapel($this->obraIrma, Papel::GerentePlanejamento->value);
        $rpDaObraA = $this->rpVazia($this->obra);

        $this->expectException(AuthorizationException::class);
        (new EmitirRequisicaoPlanejamento())->execute($rpDaObraA, $autorizadoNaOutraObra);
    }

    public function test_emitir_rp_cross_tenant_e_negado(): void
    {
        $autorizadoNoOutroTenant = $this->usuarioComPapel($this->obraOutroTenant, Papel::GerentePlanejamento->value);
        $rpDaObraA = $this->rpVazia($this->obra);

        $this->expectException(AuthorizationException::class);
        (new EmitirRequisicaoPlanejamento())->execute($rpDaObraA, $autorizadoNoOutroTenant);
    }

    // ------------------------------------------------------------------
    // EmitirRequisicaoCompra
    // ------------------------------------------------------------------

    private function rcVazia(Work $obra): RequisicaoCompra
    {
        $pacote = ItemSuprimento::create([
            'tenant_id' => $obra->tenant_id,
            'obra_id' => $obra->id,
            'nome' => 'Pacote de teste',
        ]);

        return RequisicaoCompra::create([
            'tenant_id' => $obra->tenant_id,
            'obra_id' => $obra->id,
            'item_suprimento_id' => $pacote->id,
            'status' => 'rascunho',
        ]);
    }

    public function test_emitir_rc_chamada_direta_sem_autoridade_e_negada(): void
    {
        $semAutoridade = $this->usuarioComPapel($this->obra, Papel::Encarregado->value);
        $rc = $this->rcVazia($this->obra);

        $this->expectException(AuthorizationException::class);
        (new EmitirRequisicaoCompra())->execute($rc, $semAutoridade);
    }

    public function test_emitir_rc_chamada_direta_com_autoridade_passa_da_camada_de_autorizacao(): void
    {
        $autorizado = $this->usuarioComPapel($this->obra, Papel::GerentePlanejamento->value);
        $rc = $this->rcVazia($this->obra);

        try {
            (new EmitirRequisicaoCompra())->execute($rc, $autorizado);
            $this->fail('Esperava uma exceção de negócio (RC sem fluxo/itens) — nunca de autorização.');
        } catch (AuthorizationException $e) {
            $this->fail('Usuário autorizado nunca deveria ser barrado pela camada de autorização: '.$e->getMessage());
        } catch (\App\Exceptions\RequisicaoCompraEmissaoInvalidaException $e) {
            $this->assertTrue(true);
        }
    }

    public function test_emitir_rc_same_tenant_cross_obra_e_negado(): void
    {
        $autorizadoNaOutraObra = $this->usuarioComPapel($this->obraIrma, Papel::GerentePlanejamento->value);
        $rcDaObraA = $this->rcVazia($this->obra);

        $this->expectException(AuthorizationException::class);
        (new EmitirRequisicaoCompra())->execute($rcDaObraA, $autorizadoNaOutraObra);
    }

    public function test_emitir_rc_cross_tenant_e_negado(): void
    {
        $autorizadoNoOutroTenant = $this->usuarioComPapel($this->obraOutroTenant, Papel::GerentePlanejamento->value);
        $rcDaObraA = $this->rcVazia($this->obra);

        $this->expectException(AuthorizationException::class);
        (new EmitirRequisicaoCompra())->execute($rcDaObraA, $autorizadoNoOutroTenant);
    }

    // ------------------------------------------------------------------
    // EmitirPedidoCompra
    // ------------------------------------------------------------------

    private function pedidoVazio(Work $obra): PedidoCompra
    {
        $rc = $this->rcVazia($obra);
        $fornecedor = Fornecedor::create([
            'tenant_id' => $obra->tenant_id,
            'obra_id' => $obra->id,
            'nome' => 'Fornecedor de teste',
        ]);

        return PedidoCompra::create([
            'tenant_id' => $obra->tenant_id,
            'obra_id' => $obra->id,
            'requisicao_compra_id' => $rc->id,
            'fornecedor_id' => $fornecedor->id,
            'status' => 'rascunho',
        ]);
    }

    public function test_emitir_pedido_chamada_direta_sem_autoridade_e_negada(): void
    {
        $semAutoridade = $this->usuarioComPapel($this->obra, Papel::Encarregado->value);
        $pedido = $this->pedidoVazio($this->obra);

        $this->expectException(AuthorizationException::class);
        (new EmitirPedidoCompra())->execute($pedido, $semAutoridade);
    }

    public function test_emitir_pedido_chamada_direta_com_autoridade_passa_da_camada_de_autorizacao(): void
    {
        $autorizado = $this->usuarioComPapel($this->obra, Papel::GerentePlanejamento->value);
        $pedido = $this->pedidoVazio($this->obra);

        try {
            (new EmitirPedidoCompra())->execute($pedido, $autorizado);
            $this->fail('Esperava uma exceção de negócio (Pedido sem itens/data prevista) — nunca de autorização.');
        } catch (AuthorizationException $e) {
            $this->fail('Usuário autorizado nunca deveria ser barrado pela camada de autorização: '.$e->getMessage());
        } catch (\App\Exceptions\PedidoCompraEmissaoInvalidaException $e) {
            $this->assertTrue(true);
        }
    }

    public function test_emitir_pedido_same_tenant_cross_obra_e_negado(): void
    {
        $autorizadoNaOutraObra = $this->usuarioComPapel($this->obraIrma, Papel::GerentePlanejamento->value);
        $pedidoDaObraA = $this->pedidoVazio($this->obra);

        $this->expectException(AuthorizationException::class);
        (new EmitirPedidoCompra())->execute($pedidoDaObraA, $autorizadoNaOutraObra);
    }

    public function test_emitir_pedido_cross_tenant_e_negado(): void
    {
        $autorizadoNoOutroTenant = $this->usuarioComPapel($this->obraOutroTenant, Papel::GerentePlanejamento->value);
        $pedidoDaObraA = $this->pedidoVazio($this->obra);

        $this->expectException(AuthorizationException::class);
        (new EmitirPedidoCompra())->execute($pedidoDaObraA, $autorizadoNoOutroTenant);
    }

    // ------------------------------------------------------------------
    // AprovarAjusteInventario — dupla autorização
    // ------------------------------------------------------------------

    private function itemDeInventario(Work $obra): InventarioItem
    {
        $local = LocalEstoque::create([
            'tenant_id' => $obra->tenant_id,
            'obra_id' => $obra->id,
            'nome' => 'Almoxarifado de teste',
            'tipo' => TipoLocalEstoque::Almoxarifado->value,
            'ativo' => true,
        ]);

        $inventario = InventarioEstoque::create([
            'tenant_id' => $obra->tenant_id,
            'obra_id' => $obra->id,
            'local_estoque_id' => $local->id,
            'status' => 'em_analise',
            'titulo' => 'Inventário de teste',
        ]);

        $unidade = UnidadeMedida::create([
            'tenant_id' => $obra->tenant_id,
            'codigo' => 'UN',
            'nome' => 'Unidade',
        ]);

        $material = Material::create([
            'tenant_id' => $obra->tenant_id,
            'codigo' => 'MAT-'.uniqid(),
            'descricao' => 'Material de teste',
            'unidade_medida_id' => $unidade->id,
            'modo_rastreabilidade' => 'quantitativo',
        ]);

        return InventarioItem::create([
            'tenant_id' => $obra->tenant_id,
            'inventario_estoque_id' => $inventario->id,
            'material_id' => $material->id,
            'quantidade_sistema_snapshot' => 10,
        ]);
    }

    public function test_aprovar_ajuste_chamada_direta_sem_nenhuma_das_duas_permissoes_e_negada(): void
    {
        $semAutoridade = $this->usuarioComPapel($this->obra, Papel::Encarregado->value);
        $item = $this->itemDeInventario($this->obra);

        $this->expectException(AuthorizationException::class);
        (new AprovarAjusteInventario())->execute($item, 'Justificativa de teste', $semAutoridade);
    }

    public function test_aprovar_ajuste_so_com_permissao_de_inventario_ainda_e_negado(): void
    {
        $soInventario = $this->usuarioComPermissaoUnica($this->obra, 'estoque.inventario', 'editar');
        $item = $this->itemDeInventario($this->obra);

        $this->expectException(AuthorizationException::class);
        (new AprovarAjusteInventario())->execute($item, 'Justificativa de teste', $soInventario);
    }

    public function test_aprovar_ajuste_so_com_permissao_de_movimentacao_ainda_e_negado(): void
    {
        $soMovimentacao = $this->usuarioComPermissaoUnica($this->obra, 'estoque.movimentacao', 'editar');
        $item = $this->itemDeInventario($this->obra);

        $this->expectException(AuthorizationException::class);
        (new AprovarAjusteInventario())->execute($item, 'Justificativa de teste', $soMovimentacao);
    }

    public function test_aprovar_ajuste_chamada_direta_com_autoridade_passa_da_camada_de_autorizacao(): void
    {
        $autorizado = $this->usuarioComPapel($this->obra, Papel::GerentePlanejamento->value);
        $item = $this->itemDeInventario($this->obra);

        try {
            (new AprovarAjusteInventario())->execute($item, 'Justificativa de teste com mais de 5 caracteres', $autorizado);
            $this->assertTrue(true);
        } catch (AuthorizationException $e) {
            $this->fail('Usuário autorizado (GerentePlanejamento) nunca deveria ser barrado pela camada de autorização: '.$e->getMessage());
        } catch (\App\Exceptions\AjusteInventarioInvalidoException $e) {
            $this->assertTrue(true);
        }
    }

    public function test_aprovar_ajuste_same_tenant_cross_obra_e_negado(): void
    {
        $autorizadoNaOutraObra = $this->usuarioComPapel($this->obraIrma, Papel::GerentePlanejamento->value);
        $itemDaObraA = $this->itemDeInventario($this->obra);

        $this->expectException(AuthorizationException::class);
        (new AprovarAjusteInventario())->execute($itemDaObraA, 'Justificativa de teste', $autorizadoNaOutraObra);
    }

    public function test_aprovar_ajuste_cross_tenant_e_negado(): void
    {
        $autorizadoNoOutroTenant = $this->usuarioComPapel($this->obraOutroTenant, Papel::GerentePlanejamento->value);
        $itemDaObraA = $this->itemDeInventario($this->obra);

        $this->expectException(AuthorizationException::class);
        (new AprovarAjusteInventario())->execute($itemDaObraA, 'Justificativa de teste', $autorizadoNoOutroTenant);
    }

    // ------------------------------------------------------------------
    // CriarAdjudicacaoRequisicaoCompra
    // ------------------------------------------------------------------

    public function test_criar_adjudicacao_chamada_direta_sem_autoridade_e_negada(): void
    {
        $semAutoridade = $this->usuarioComPapel($this->obra, Papel::Encarregado->value);
        $rc = $this->rcVazia($this->obra);
        $fornecedor = Fornecedor::create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'F']);

        $this->expectException(AuthorizationException::class);
        (new CriarAdjudicacaoRequisicaoCompra())->execute($rc, $fornecedor, 'Justificativa', null, null, $semAutoridade);
    }

    public function test_criar_adjudicacao_chamada_direta_com_autoridade_passa_da_camada_de_autorizacao(): void
    {
        $autorizado = $this->usuarioComPapel($this->obra, Papel::GerentePlanejamento->value);
        $rc = $this->rcVazia($this->obra);
        $fornecedor = Fornecedor::create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'F']);

        try {
            (new CriarAdjudicacaoRequisicaoCompra())->execute($rc, $fornecedor, 'Justificativa', null, null, $autorizado);
            $this->fail('Esperava uma exceção de negócio (RC ainda Rascunho, não adjudicável) — nunca de autorização.');
        } catch (AuthorizationException $e) {
            $this->fail('Usuário autorizado nunca deveria ser barrado pela camada de autorização: '.$e->getMessage());
        } catch (\App\Exceptions\RequisicaoCompraAdjudicacaoInvalidaException $e) {
            $this->assertTrue(true);
        }
    }

    public function test_criar_adjudicacao_same_tenant_cross_obra_e_negado(): void
    {
        $autorizadoNaOutraObra = $this->usuarioComPapel($this->obraIrma, Papel::GerentePlanejamento->value);
        $rcDaObraA = $this->rcVazia($this->obra);
        $fornecedor = Fornecedor::create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'F']);

        $this->expectException(AuthorizationException::class);
        (new CriarAdjudicacaoRequisicaoCompra())->execute($rcDaObraA, $fornecedor, 'Justificativa', null, null, $autorizadoNaOutraObra);
    }

    public function test_criar_adjudicacao_cross_tenant_e_negado(): void
    {
        $autorizadoNoOutroTenant = $this->usuarioComPapel($this->obraOutroTenant, Papel::GerentePlanejamento->value);
        $rcDaObraA = $this->rcVazia($this->obra);
        $fornecedor = Fornecedor::create(['tenant_id' => $this->obra->tenant_id, 'obra_id' => $this->obra->id, 'nome' => 'F']);

        $this->expectException(AuthorizationException::class);
        (new CriarAdjudicacaoRequisicaoCompra())->execute($rcDaObraA, $fornecedor, 'Justificativa', null, null, $autorizadoNoOutroTenant);
    }

    // ------------------------------------------------------------------
    // AtualizarAdjudicacaoRequisicaoCompra::cancelar()
    // ------------------------------------------------------------------

    private function adjudicacaoAtiva(Work $obra): RequisicaoCompraAdjudicacao
    {
        $rc = $this->rcVazia($obra);
        $fornecedor = Fornecedor::create(['tenant_id' => $obra->tenant_id, 'obra_id' => $obra->id, 'nome' => 'F']);

        return RequisicaoCompraAdjudicacao::create([
            'tenant_id' => $obra->tenant_id,
            'requisicao_compra_id' => $rc->id,
            'fornecedor_id' => $fornecedor->id,
            'status' => StatusAdjudicacaoRequisicaoCompra::Ativa,
            'decidido_por_id' => null,
            'decidido_em' => now(),
            'justificativa' => 'Justificativa',
        ]);
    }

    public function test_cancelar_adjudicacao_chamada_direta_sem_autoridade_e_negada(): void
    {
        $semAutoridade = $this->usuarioComPapel($this->obra, Papel::Encarregado->value);
        $adjudicacao = $this->adjudicacaoAtiva($this->obra);

        $this->expectException(AuthorizationException::class);
        (new AtualizarAdjudicacaoRequisicaoCompra())->cancelar($adjudicacao, $semAutoridade, null);
    }

    public function test_cancelar_adjudicacao_chamada_direta_com_autoridade_passa_da_camada_de_autorizacao(): void
    {
        $autorizado = $this->usuarioComPapel($this->obra, Papel::GerentePlanejamento->value);
        $adjudicacao = $this->adjudicacaoAtiva($this->obra);

        try {
            (new AtualizarAdjudicacaoRequisicaoCompra())->cancelar($adjudicacao, $autorizado, null);
            $this->assertTrue(true);
        } catch (AuthorizationException $e) {
            $this->fail('Usuário autorizado nunca deveria ser barrado pela camada de autorização: '.$e->getMessage());
        }
    }

    public function test_cancelar_adjudicacao_same_tenant_cross_obra_e_negado(): void
    {
        $autorizadoNaOutraObra = $this->usuarioComPapel($this->obraIrma, Papel::GerentePlanejamento->value);
        $adjudicacaoDaObraA = $this->adjudicacaoAtiva($this->obra);

        $this->expectException(AuthorizationException::class);
        (new AtualizarAdjudicacaoRequisicaoCompra())->cancelar($adjudicacaoDaObraA, $autorizadoNaOutraObra, null);
    }

    // ------------------------------------------------------------------
    // FASE 2E.CORREÇÃO — AlterarLiberacaoRevisaoDocumento::liberar()/revogar()
    // ------------------------------------------------------------------

    private function revisaoDeDocumento(Work $obra): DocumentoEngenhariaRevisao
    {
        $documento = DocumentoEngenharia::create([
            'tenant_id' => $obra->tenant_id,
            'obra_id' => $obra->id,
            'codigo' => 'DOC-'.uniqid(),
            'descricao' => 'Documento de teste',
        ]);

        return $documento->revisoes()->create([
            'tenant_id' => $obra->tenant_id,
            'revisao' => 'R1',
            'descricao' => 'Emissão R1',
        ])->fresh();
    }

    public function test_liberar_revisao_chamada_direta_sem_autoridade_e_negada(): void
    {
        $semAutoridade = $this->usuarioComPapel($this->obra, Papel::GerentePlanejamento->value);
        $revisao = $this->revisaoDeDocumento($this->obra);

        $this->expectException(AuthorizationException::class);
        (new AlterarLiberacaoRevisaoDocumento())->liberar($revisao, $semAutoridade);
    }

    public function test_liberar_revisao_chamada_direta_com_autoridade_passa_da_camada_de_autorizacao(): void
    {
        $autorizado = $this->usuarioComPapel($this->obra, Papel::Admin->value);
        $revisao = $this->revisaoDeDocumento($this->obra);

        try {
            (new AlterarLiberacaoRevisaoDocumento())->liberar($revisao, $autorizado);
            $this->assertTrue($revisao->fresh()->estaLiberadaParaConstrucao());
        } catch (AuthorizationException $e) {
            $this->fail('Usuário autorizado (Admin) nunca deveria ser barrado pela camada de autorização: '.$e->getMessage());
        }
    }

    public function test_liberar_revisao_same_tenant_cross_obra_e_negado(): void
    {
        $autorizadoNaOutraObra = $this->usuarioComPapel($this->obraIrma, Papel::Admin->value);
        $revisaoDaObraA = $this->revisaoDeDocumento($this->obra);

        $this->expectException(AuthorizationException::class);
        (new AlterarLiberacaoRevisaoDocumento())->liberar($revisaoDaObraA, $autorizadoNaOutraObra);
    }

    public function test_liberar_revisao_cross_tenant_e_negado(): void
    {
        $autorizadoNoOutroTenant = $this->usuarioComPapel($this->obraOutroTenant, Papel::Admin->value);
        $revisaoDaObraA = $this->revisaoDeDocumento($this->obra);

        $this->expectException(AuthorizationException::class);
        (new AlterarLiberacaoRevisaoDocumento())->liberar($revisaoDaObraA, $autorizadoNoOutroTenant);
    }

    /**
     * Prova que `editar` sozinho (sem `liberar_para_construcao`) nunca
     * basta — as duas são capacidades DELIBERADAMENTE separadas desde a
     * Fase 2B, mesmo os 5 papéis padrão colapsando as duas no mesmo tier
     * Admin (um Perfil personalizado pode ter só uma das duas).
     */
    public function test_liberar_revisao_capacidade_editar_isolada_sem_liberar_para_construcao_e_negado(): void
    {
        $soEditar = $this->usuarioComPermissaoUnica($this->obra, 'engenharia.pacotes', 'editar');
        $revisao = $this->revisaoDeDocumento($this->obra);

        $this->expectException(AuthorizationException::class);
        (new AlterarLiberacaoRevisaoDocumento())->liberar($revisao, $soEditar);
    }

    /**
     * Multiperfil: `editar` vem de um Perfil, `liberar_para_construcao`
     * vem de OUTRO — o resolver da Fase 2B une as capacidades de TODOS
     * os perfis efetivos do usuário na obra, então a combinação deve
     * autorizar, mesmo sem nenhum perfil isolado ter as duas.
     */
    public function test_liberar_revisao_multiperfil_combina_capacidades_e_passa(): void
    {
        $user = User::factory()->create(['tenant_id' => $this->obra->tenant_id]);

        $perfilEditar = Perfil::create(['tenant_id' => $this->obra->tenant_id, 'nome' => 'Só editar Engenharia']);
        PerfilPermissao::create([
            'tenant_id' => $this->obra->tenant_id,
            'perfil_id' => $perfilEditar->id,
            'funcionalidade' => 'engenharia.pacotes',
            'acao' => 'editar',
        ]);

        $perfilLiberar = Perfil::create(['tenant_id' => $this->obra->tenant_id, 'nome' => 'Só liberar para construção']);
        PerfilPermissao::create([
            'tenant_id' => $this->obra->tenant_id,
            'perfil_id' => $perfilLiberar->id,
            'funcionalidade' => 'engenharia.pacotes',
            'acao' => 'liberar_para_construcao',
        ]);

        $this->obra->users()->attach($user->id, ['perfil_id' => $perfilEditar->id]);
        AtribuicaoPerfilObra::definirPerfilUnico($this->obra, $user->id, $perfilEditar->id);
        ObraUserPerfil::create([
            'tenant_id' => $this->obra->tenant_id,
            'work_id' => $this->obra->id,
            'user_id' => $user->id,
            'perfil_id' => $perfilLiberar->id,
        ]);

        $revisao = $this->revisaoDeDocumento($this->obra);

        try {
            (new AlterarLiberacaoRevisaoDocumento())->liberar($revisao, $user);
            $this->assertTrue($revisao->fresh()->estaLiberadaParaConstrucao());
        } catch (AuthorizationException $e) {
            $this->fail('Usuário com liberar_para_construcao via um segundo Perfil nunca deveria ser barrado: '.$e->getMessage());
        }
    }

    /** Membro sem NENHUM Perfil na obra (vínculo puro em obra_user) — negado. */
    public function test_liberar_revisao_zero_perfis_e_negado(): void
    {
        $membroSemPerfil = User::factory()->create(['tenant_id' => $this->obra->tenant_id]);
        $this->obra->users()->attach($membroSemPerfil->id);

        $revisao = $this->revisaoDeDocumento($this->obra);

        $this->expectException(AuthorizationException::class);
        (new AlterarLiberacaoRevisaoDocumento())->liberar($revisao, $membroSemPerfil);
    }

    public function test_revogar_revisao_segue_mesma_autoridade(): void
    {
        $autorizado = $this->usuarioComPapel($this->obra, Papel::Admin->value);
        $revisao = $this->revisaoDeDocumento($this->obra);
        (new AlterarLiberacaoRevisaoDocumento())->liberar($revisao, $autorizado);

        $semAutoridade = $this->usuarioComPapel($this->obraIrma, Papel::GerentePlanejamento->value);

        $this->expectException(AuthorizationException::class);
        (new AlterarLiberacaoRevisaoDocumento())->revogar($revisao->fresh(), $semAutoridade);
    }

    // ------------------------------------------------------------------
    // FASE 2E.CORREÇÃO — AtualizarAdjudicacaoRequisicaoCompra::adicionarItem()/removerItem()
    // ------------------------------------------------------------------

    /**
     * `$rcItem`/`$item` NUNCA precisam ser referencialmente válidos pra
     * provar a camada de autorização — ela é checada ANTES de qualquer
     * lock/leitura de negócio dentro da Action. Só `$adjudicacao`
     * precisa ser real (é dela que a obra é derivada). Autorização que
     * passa cai num `ModelNotFoundException` ao tentar travar um item
     * inexistente — nunca uma exceção de negócio própria, mas prova
     * igualmente que a AuthorizationException não foi lançada.
     */
    public function test_adicionar_item_adjudicacao_chamada_direta_sem_autoridade_e_negada(): void
    {
        $semAutoridade = $this->usuarioComPapel($this->obra, Papel::Encarregado->value);
        $adjudicacao = $this->adjudicacaoAtiva($this->obra);
        $rcItemInexistente = (new RequisicaoCompraItem())->forceFill(['id' => (string) Str::ulid()]);

        $this->expectException(AuthorizationException::class);
        (new AtualizarAdjudicacaoRequisicaoCompra())->adicionarItem($adjudicacao, $rcItemInexistente, null, 10, $semAutoridade);
    }

    public function test_adicionar_item_adjudicacao_chamada_direta_com_autoridade_passa_da_camada_de_autorizacao(): void
    {
        $autorizado = $this->usuarioComPapel($this->obra, Papel::GerentePlanejamento->value);
        $adjudicacao = $this->adjudicacaoAtiva($this->obra);
        $rcItemInexistente = (new RequisicaoCompraItem())->forceFill(['id' => (string) Str::ulid()]);

        try {
            (new AtualizarAdjudicacaoRequisicaoCompra())->adicionarItem($adjudicacao, $rcItemInexistente, null, 10, $autorizado);
            $this->fail('Esperava ModelNotFoundException (item inexistente) — nunca AuthorizationException.');
        } catch (AuthorizationException $e) {
            $this->fail('Usuário autorizado nunca deveria ser barrado pela camada de autorização: '.$e->getMessage());
        } catch (ModelNotFoundException $e) {
            $this->assertTrue(true);
        }
    }

    public function test_adicionar_item_adjudicacao_same_tenant_cross_obra_e_negado(): void
    {
        $autorizadoNaOutraObra = $this->usuarioComPapel($this->obraIrma, Papel::GerentePlanejamento->value);
        $adjudicacaoDaObraA = $this->adjudicacaoAtiva($this->obra);
        $rcItemInexistente = (new RequisicaoCompraItem())->forceFill(['id' => (string) Str::ulid()]);

        $this->expectException(AuthorizationException::class);
        (new AtualizarAdjudicacaoRequisicaoCompra())->adicionarItem($adjudicacaoDaObraA, $rcItemInexistente, null, 10, $autorizadoNaOutraObra);
    }

    public function test_adicionar_item_adjudicacao_cross_tenant_e_negado(): void
    {
        $autorizadoNoOutroTenant = $this->usuarioComPapel($this->obraOutroTenant, Papel::GerentePlanejamento->value);
        $adjudicacaoDaObraA = $this->adjudicacaoAtiva($this->obra);
        $rcItemInexistente = (new RequisicaoCompraItem())->forceFill(['id' => (string) Str::ulid()]);

        $this->expectException(AuthorizationException::class);
        (new AtualizarAdjudicacaoRequisicaoCompra())->adicionarItem($adjudicacaoDaObraA, $rcItemInexistente, null, 10, $autorizadoNoOutroTenant);
    }

    public function test_remover_item_adjudicacao_chamada_direta_sem_autoridade_e_negada(): void
    {
        $semAutoridade = $this->usuarioComPapel($this->obra, Papel::Encarregado->value);
        $adjudicacao = $this->adjudicacaoAtiva($this->obra);
        $itemInexistente = (new RequisicaoCompraAdjudicacaoItem())->forceFill([
            'id' => (string) Str::ulid(),
            'requisicao_compra_adjudicacao_id' => $adjudicacao->id,
        ]);

        $this->expectException(AuthorizationException::class);
        (new AtualizarAdjudicacaoRequisicaoCompra())->removerItem($itemInexistente, $semAutoridade);
    }

    public function test_remover_item_adjudicacao_chamada_direta_com_autoridade_passa_da_camada_de_autorizacao(): void
    {
        $autorizado = $this->usuarioComPapel($this->obra, Papel::GerentePlanejamento->value);
        $adjudicacao = $this->adjudicacaoAtiva($this->obra);
        $itemInexistente = (new RequisicaoCompraAdjudicacaoItem())->forceFill([
            'id' => (string) Str::ulid(),
            'requisicao_compra_adjudicacao_id' => $adjudicacao->id,
        ]);

        try {
            (new AtualizarAdjudicacaoRequisicaoCompra())->removerItem($itemInexistente, $autorizado);
            $this->fail('Esperava ModelNotFoundException (item inexistente) — nunca AuthorizationException.');
        } catch (AuthorizationException $e) {
            $this->fail('Usuário autorizado nunca deveria ser barrado pela camada de autorização: '.$e->getMessage());
        } catch (ModelNotFoundException $e) {
            $this->assertTrue(true);
        }
    }
}
