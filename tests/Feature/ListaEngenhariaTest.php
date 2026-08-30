<?php

namespace Tests\Feature;

use App\Models\Disciplina;
use App\Models\DocumentoEngenharia;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ciclo 19, Etapa 19.1.CORREÇÃO — cobertura estrutural da entidade nova
 * `ListaEngenharia`, que a 19.1 original não tinha. Cobre os itens A-D
 * da matriz de testes obrigatória do pedido de correção.
 */
class ListaEngenhariaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;
    private DocumentoEngenharia $documento;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($user);

        $this->documento = DocumentoEngenharia::create([
            'obra_id' => $this->obra->id,
            'codigo' => 'ISO-001',
            'descricao' => 'Isometrico',
        ]);
    }

    /**
     * Teste A — duas LMs Material na mesma revisão coexistem, cada uma
     * com identidade própria (prova direta do que a 19.1 original NÃO
     * suportava — ver seção 1 do relatório de correção).
     */
    public function test_a_duas_listas_de_material_na_mesma_revisao_coexistem(): void
    {
        $revisao = $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);

        $lm1 = ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        $lm2 = ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'material', 'codigo' => 'LM-002']);

        $this->assertNotSame($lm1->id, $lm2->id);
        $this->assertSame(2, ListaEngenharia::where('documento_engenharia_revisao_id', $revisao->id)->count());
    }

    /**
     * Teste B — LM + LI na mesma revisão, tipos distintos, sem colisão
     * de código entre si (códigos podem até coincidir entre tipos
     * diferentes, já que o unique é (revisao, tipo, codigo)).
     */
    public function test_b_lm_e_li_coexistem_na_mesma_revisao(): void
    {
        $revisao = $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);

        $lm = ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'material', 'codigo' => '001']);
        $li = ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'instrumento', 'codigo' => '001']);

        $this->assertSame('material', $lm->tipo->value);
        $this->assertSame('instrumento', $li->tipo->value);
        $this->assertSame(2, ListaEngenharia::where('documento_engenharia_revisao_id', $revisao->id)->count());
    }

    /**
     * Teste C — lista pertence exatamente à revisão em que foi criada.
     * Cria a lista ENQUANTO R1 ainda é vigente (19.1.HARDENING: criar
     * lista numa revisão já superada é bloqueado — testado à parte em
     * ListaEngenhariaHardeningTest) — R2 nasce DEPOIS, e a FK da lista
     * nunca migra/muda mesmo com R2 se tornando a nova vigente.
     */
    public function test_c_lista_pertence_a_revisao_exata(): void
    {
        $r1 = $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now()->subDay(), 'descricao' => 'E1']);
        $listaR1 = ListaEngenharia::create(['documento_engenharia_revisao_id' => $r1->id, 'tipo' => 'material', 'codigo' => 'LM-001']);

        $r2 = $this->documento->revisoes()->create(['revisao' => 'R2', 'data_emissao' => now(), 'descricao' => 'E2']);

        $this->assertSame($r1->id, $listaR1->revisao->id);
        $this->assertNotSame($r2->id, $listaR1->documento_engenharia_revisao_id);
    }

    /** Teste D — R2 não herda nenhuma lista de R1. */
    public function test_d_nova_revisao_nao_herda_listas_da_anterior(): void
    {
        $r1 = $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now()->subDay(), 'descricao' => 'E1']);
        ListaEngenharia::create(['documento_engenharia_revisao_id' => $r1->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        ListaEngenharia::create(['documento_engenharia_revisao_id' => $r1->id, 'tipo' => 'material', 'codigo' => 'LM-002']);

        $r2 = $this->documento->revisoes()->create(['revisao' => 'R2', 'data_emissao' => now(), 'descricao' => 'E2']);

        $this->assertSame(0, ListaEngenharia::where('documento_engenharia_revisao_id', $r2->id)->count());
        $this->assertSame(2, ListaEngenharia::where('documento_engenharia_revisao_id', $r1->id)->count());
    }

    /**
     * Teste F (itens preservam lista de origem) + prova de que a
     * revisão continua acessível via item->lista->revisao (seção 4).
     */
    public function test_f_item_preserva_lista_de_origem_e_revisao_e_acessivel_pela_cadeia(): void
    {
        $revisao = $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        $item = ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'descricao' => 'Item', 'quantidade' => 10]);

        $item->refresh();
        $this->assertSame($lista->id, $item->lista_engenharia_id);
        $this->assertSame($revisao->id, $item->lista->revisao->id);
        $this->assertSame($this->documento->id, $item->lista->revisao->documento->id);
    }

    /** Teste I — código de lista duplicado (mesma revisão+tipo) bloqueado a nível de banco. */
    public function test_i_codigo_de_lista_duplicado_na_mesma_revisao_e_tipo_e_bloqueado(): void
    {
        $revisao = $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'material', 'codigo' => 'LM-001']);

        $this->expectException(QueryException::class);
        ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
    }

    /** Mesmo código, tipo diferente, na mesma revisão — NÃO colide (unique inclui tipo). */
    public function test_codigo_igual_em_tipos_diferentes_na_mesma_revisao_nao_colide(): void
    {
        $revisao = $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        $li = ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'instrumento', 'codigo' => 'LM-001']);

        $this->assertNotNull($li->id);
    }

    /**
     * Teste J — cross-obra: lista de uma revisão de outra obra nunca é
     * resolvível/associável a partir do contexto da obra atual.
     */
    public function test_j_lista_de_outra_obra_nao_e_alcancavel_pelo_escopo_da_obra_atual(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $outroDocumento = DocumentoEngenharia::create(['obra_id' => $outraObra->id, 'codigo' => 'X', 'descricao' => 'X']);
        $outraRevisao = $outroDocumento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $listaDeOutraObra = ListaEngenharia::create(['documento_engenharia_revisao_id' => $outraRevisao->id, 'tipo' => 'material', 'codigo' => 'LM-001']);

        $encontrada = ListaEngenharia::whereHas(
            'revisao.documento',
            fn ($q) => $q->where('obra_id', $this->obra->id)
        )->find($listaDeOutraObra->id);

        $this->assertNull($encontrada);
    }

    /** Teste K — cross-tenant: mesmo padrão de isolamento do resto do projeto. */
    public function test_k_lista_de_outro_tenant_nunca_e_visivel(): void
    {
        $outroTenant = Tenant::factory()->create();

        $listaOutroTenant = \App\Support\TenantContext::actingAs($outroTenant, function () use ($outroTenant) {
            $obra = Work::factory()->create(['tenant_id' => $outroTenant->id]);
            $doc = DocumentoEngenharia::create(['obra_id' => $obra->id, 'codigo' => 'X', 'descricao' => 'X']);
            $rev = $doc->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);

            return ListaEngenharia::create(['documento_engenharia_revisao_id' => $rev->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        });

        $this->assertNull(ListaEngenharia::find($listaOutroTenant->id));
    }

    /**
     * Teste R — a futura conciliação por lista (19.2) é computável hoje,
     * mesmo sem RP: soma de quantidade por lista é uma agregação direta
     * sobre `itens()`, sem nenhum obstáculo estrutural.
     */
    public function test_r_soma_de_quantidade_por_lista_e_computavel_hoje(): void
    {
        $revisao = $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lista = ListaEngenharia::create(['documento_engenharia_revisao_id' => $revisao->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'descricao' => 'A', 'quantidade' => 10]);
        ItemTakeOff::create(['lista_engenharia_id' => $lista->id, 'descricao' => 'B', 'quantidade' => 15]);

        $totalPrevisto = $lista->itens()->sum('quantidade');
        $qtdItens = $lista->itens()->count();

        $this->assertSame('25.000', (string) $totalPrevisto);
        $this->assertSame(2, $qtdItens);
    }
}
