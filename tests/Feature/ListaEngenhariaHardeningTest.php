<?php

namespace Tests\Feature;

use App\Exceptions\ListaEngenhariaImutavelException;
use App\Imports\TakeOffImporter;
use App\Models\DocumentoEngenharia;
use App\Models\FamiliaMaterial;
use App\Models\ItemTakeOff;
use App\Models\ListaEngenharia;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Ciclo 19, Etapa 19.1.HARDENING — congelamento histórico de LM/LI.
 * Cobertura A-N da matriz obrigatória do pedido.
 */
class ListaEngenhariaHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private DocumentoEngenharia $documento;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user);

        $this->documento = DocumentoEngenharia::create([
            'obra_id' => $this->obra->id,
            'codigo' => 'ISO-001',
            'descricao' => 'Isometrico',
        ]);
    }

    // ---- A/B/C: revisão vigente aceita CRUD completo ----

    public function test_a_lista_vigente_aceita_inclusao_de_item(): void
    {
        $r1 = $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lm1 = ListaEngenharia::create(['documento_engenharia_revisao_id' => $r1->id, 'tipo' => 'material', 'codigo' => 'LM-001']);

        $item = ItemTakeOff::create(['lista_engenharia_id' => $lm1->id, 'codigo' => 'A', 'descricao' => 'A', 'quantidade' => 10]);

        $this->assertNotNull($item->id);
        $this->assertDatabaseHas('itens_take_off', ['id' => $item->id]);
    }

    public function test_b_lista_vigente_aceita_update_de_item(): void
    {
        $r1 = $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lm1 = ListaEngenharia::create(['documento_engenharia_revisao_id' => $r1->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        $item = ItemTakeOff::create(['lista_engenharia_id' => $lm1->id, 'codigo' => 'A', 'descricao' => 'A', 'quantidade' => 10]);

        $item->update(['quantidade' => 15]);

        $this->assertSame('15.000', $item->fresh()->quantidade);
    }

    public function test_c_lista_vigente_aceita_reimportacao(): void
    {
        $r1 = $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lm1 = ListaEngenharia::create(['documento_engenharia_revisao_id' => $r1->id, 'tipo' => 'material', 'codigo' => 'LM-001']);

        $importer = new TakeOffImporter();
        $linhas = [['linha' => 2, 'codigo' => 'A', 'descricao' => 'A', 'unidade' => null, 'familia' => null, 'disciplina' => null, 'quantidade' => 10.0, 'observacoes' => null]];
        $resultado = $importer->aplicar($linhas, $lm1, $this->user->id);

        $this->assertSame(1, $resultado['novos']);
    }

    // ---- D/E: R2 congela R1 ----

    public function test_d_r2_congela_lista_r1_bloqueia_adicionar_item(): void
    {
        $r1 = $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now()->subDay(), 'descricao' => 'E1']);
        $lm1 = ListaEngenharia::create(['documento_engenharia_revisao_id' => $r1->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        ItemTakeOff::create(['lista_engenharia_id' => $lm1->id, 'codigo' => 'A', 'descricao' => 'A', 'quantidade' => 10]);

        $this->documento->revisoes()->create(['revisao' => 'R2', 'data_emissao' => now(), 'descricao' => 'E2']);

        $this->expectException(ListaEngenhariaImutavelException::class);
        ItemTakeOff::create(['lista_engenharia_id' => $lm1->id, 'codigo' => 'B', 'descricao' => 'B', 'quantidade' => 5]);
    }

    public function test_e_r2_congela_item_r1_bloqueia_editar(): void
    {
        $r1 = $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now()->subDay(), 'descricao' => 'E1']);
        $lm1 = ListaEngenharia::create(['documento_engenharia_revisao_id' => $r1->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        $item = ItemTakeOff::create(['lista_engenharia_id' => $lm1->id, 'codigo' => 'A', 'descricao' => 'A', 'quantidade' => 10]);

        $this->documento->revisoes()->create(['revisao' => 'R2', 'data_emissao' => now(), 'descricao' => 'E2']);

        $this->expectException(ListaEngenhariaImutavelException::class);
        $item->update(['quantidade' => 99]);
    }

    /** Nenhuma evidência muda mesmo tentando (seção 7 do pedido). */
    public function test_nenhuma_evidencia_muda_apos_tentativas_bloqueadas_em_revisao_historica(): void
    {
        $r1 = $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now()->subDay(), 'descricao' => 'E1']);
        $lm1 = ListaEngenharia::create(['documento_engenharia_revisao_id' => $r1->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        $item = ItemTakeOff::create(['lista_engenharia_id' => $lm1->id, 'codigo' => 'A', 'descricao' => 'A', 'quantidade' => 10]);
        $this->documento->revisoes()->create(['revisao' => 'R2', 'data_emissao' => now(), 'descricao' => 'E2']);

        try { ItemTakeOff::create(['lista_engenharia_id' => $lm1->id, 'codigo' => 'B', 'descricao' => 'B', 'quantidade' => 1]); } catch (ListaEngenhariaImutavelException) {}
        try { $item->update(['quantidade' => 999]); } catch (ListaEngenhariaImutavelException) {}
        try { $item->delete(); } catch (ListaEngenhariaImutavelException) {}
        try { $lm1->delete(); } catch (ListaEngenhariaImutavelException) {}

        $this->assertSame(1, ItemTakeOff::where('lista_engenharia_id', $lm1->id)->count());
        $this->assertSame('10.000', $item->fresh()->quantidade);
        $this->assertNotNull($item->fresh());
        $this->assertNotNull($lm1->fresh());
    }

    // ---- F: reimport revisão superada sem write parcial ----

    public function test_f_reimport_r1_superada_e_rejeitado_sem_write_parcial(): void
    {
        $r1 = $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now()->subDay(), 'descricao' => 'E1']);
        $lm1 = ListaEngenharia::create(['documento_engenharia_revisao_id' => $r1->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        ItemTakeOff::create(['lista_engenharia_id' => $lm1->id, 'codigo' => 'A', 'descricao' => 'A original', 'quantidade' => 10]);
        $this->documento->revisoes()->create(['revisao' => 'R2', 'data_emissao' => now(), 'descricao' => 'E2']);

        $importer = new TakeOffImporter();
        $linhas = [
            ['linha' => 2, 'codigo' => 'A', 'descricao' => 'A alterado', 'unidade' => null, 'familia' => null, 'disciplina' => null, 'quantidade' => 99.0, 'observacoes' => null],
            ['linha' => 3, 'codigo' => 'NOVO', 'descricao' => 'Novo item', 'unidade' => null, 'familia' => null, 'disciplina' => null, 'quantidade' => 1.0, 'observacoes' => null],
        ];

        $this->expectException(ListaEngenhariaImutavelException::class);

        try {
            $importer->aplicar($linhas, $lm1, $this->user->id);
        } finally {
            // Mesmo capturando pra inspecionar, nada deve ter mudado.
            $this->assertSame(1, ItemTakeOff::where('lista_engenharia_id', $lm1->id)->count());
            $this->assertSame('A original', ItemTakeOff::where('lista_engenharia_id', $lm1->id)->where('codigo', 'A')->first()->descricao);
            $this->assertFalse(ItemTakeOff::where('lista_engenharia_id', $lm1->id)->where('codigo', 'NOVO')->exists());
        }
    }

    // ---- G/H/I: delete de Lista sempre bloqueado ----

    public function test_g_delete_lista_vigente_bloqueado(): void
    {
        $r1 = $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lm1 = ListaEngenharia::create(['documento_engenharia_revisao_id' => $r1->id, 'tipo' => 'material', 'codigo' => 'LM-001']);

        $this->expectException(ListaEngenhariaImutavelException::class);
        $lm1->delete();
    }

    public function test_h_delete_lista_superada_bloqueado(): void
    {
        $r1 = $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now()->subDay(), 'descricao' => 'E1']);
        $lm1 = ListaEngenharia::create(['documento_engenharia_revisao_id' => $r1->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        $this->documento->revisoes()->create(['revisao' => 'R2', 'data_emissao' => now(), 'descricao' => 'E2']);

        $this->expectException(ListaEngenhariaImutavelException::class);
        $lm1->delete();
    }

    public function test_h2_forcedelete_lista_tambem_bloqueado(): void
    {
        $r1 = $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lm1 = ListaEngenharia::create(['documento_engenharia_revisao_id' => $r1->id, 'tipo' => 'material', 'codigo' => 'LM-001']);

        $this->expectException(ListaEngenhariaImutavelException::class);
        $lm1->forceDelete();
    }

    public function test_i_tentativa_de_delete_preserva_todos_os_itens(): void
    {
        $r1 = $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lm1 = ListaEngenharia::create(['documento_engenharia_revisao_id' => $r1->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        ItemTakeOff::create(['lista_engenharia_id' => $lm1->id, 'codigo' => 'A', 'descricao' => 'A', 'quantidade' => 1]);
        ItemTakeOff::create(['lista_engenharia_id' => $lm1->id, 'codigo' => 'B', 'descricao' => 'B', 'quantidade' => 2]);
        ItemTakeOff::create(['lista_engenharia_id' => $lm1->id, 'codigo' => 'C', 'descricao' => 'C', 'quantidade' => 3]);

        try {
            $lm1->delete();
        } catch (ListaEngenhariaImutavelException) {
        }

        $this->assertSame(3, ItemTakeOff::where('lista_engenharia_id', $lm1->id)->count());
        $this->assertNotNull(ListaEngenharia::find($lm1->id));
    }

    // ---- J: R2 recebe lista nova normalmente ----

    public function test_j_r2_recebe_lista_nova_normalmente(): void
    {
        $r1 = $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now()->subDay(), 'descricao' => 'E1']);
        ListaEngenharia::create(['documento_engenharia_revisao_id' => $r1->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        $r2 = $this->documento->revisoes()->create(['revisao' => 'R2', 'data_emissao' => now(), 'descricao' => 'E2']);

        $lm2 = ListaEngenharia::create(['documento_engenharia_revisao_id' => $r2->id, 'tipo' => 'material', 'codigo' => 'LM2-001']);

        $this->assertNotNull($lm2->id);
        $this->assertTrue($lm2->estaVigente());
    }

    // ---- K: revisão retroativa não congela a vigente errada ----

    public function test_k_revisao_retroativa_nao_congela_revisao_vigente_errada(): void
    {
        $r1 = $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now()->subDays(10), 'descricao' => 'E1']);
        $r2 = $this->documento->revisoes()->create(['revisao' => 'R2', 'data_emissao' => now(), 'descricao' => 'E2']);
        $lm2 = ListaEngenharia::create(['documento_engenharia_revisao_id' => $r2->id, 'tipo' => 'material', 'codigo' => 'LM2-001']);

        // Revisão criada DEPOIS, mas com data de emissão retroativa (entre R1 e R2).
        $this->documento->revisoes()->create(['revisao' => 'R-RETRO', 'data_emissao' => now()->subDays(5), 'descricao' => 'Retroativa']);

        // R2 continua vigente pela ordem canônica (data_emissao mais recente) —
        // LM2 continua editável, nunca congelada pela retroativa.
        $this->assertTrue($lm2->fresh()->estaVigente());
        $item = ItemTakeOff::create(['lista_engenharia_id' => $lm2->id, 'codigo' => 'X', 'descricao' => 'X', 'quantidade' => 1]);
        $this->assertNotNull($item->id);
    }

    // ---- L: família normalizada não duplica ----

    public function test_l_familia_case_trim_nao_duplica(): void
    {
        $f1 = FamiliaMaterial::create(['nome' => 'Tubulacao']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        FamiliaMaterial::create(['nome' => ' TUBULACAO ']);
    }

    public function test_l2_familia_acento_tambem_nao_duplica(): void
    {
        FamiliaMaterial::create(['nome' => 'Tubulação']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        FamiliaMaterial::create(['nome' => 'TUBULACAO']);
    }

    public function test_l3_importer_usa_familia_ja_normalizada_sem_duplicar(): void
    {
        $r1 = $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lm1 = ListaEngenharia::create(['documento_engenharia_revisao_id' => $r1->id, 'tipo' => 'material', 'codigo' => 'LM-001']);
        FamiliaMaterial::create(['nome' => 'Tubulacao']);

        $importer = new TakeOffImporter();
        $linhas = [['linha' => 2, 'codigo' => 'A', 'descricao' => 'A', 'unidade' => null, 'familia' => 'TUBULACAO', 'disciplina' => null, 'quantidade' => 1.0, 'observacoes' => null]];
        $importer->aplicar($linhas, $lm1, $this->user->id);

        $this->assertSame(1, FamiliaMaterial::whereRaw('LOWER(nome) = ?', ['tubulacao'])->count());
    }

    /**
     * Teste L4 — MICROAUDITORIA FINAL, Seção 10: o unique(tenant_id, nome)
     * é escopado por tenant de verdade, nunca só por `nome` — o mesmo
     * nome (mesma grafia, sem variação de caixa/acento) precisa ser
     * livremente criável em OUTRO tenant, sem colidir com o Tenant A.
     * Confirma que o global scope de BelongsToTenant não falseia nem a
     * escrita nem a leitura entre os dois tenants.
     */
    public function test_l4_familia_mesmo_nome_em_outro_tenant_nao_colide(): void
    {
        FamiliaMaterial::create(['nome' => 'Tubulacao']);

        $outroTenant = Tenant::factory()->create();
        $familiaOutroTenant = \App\Support\TenantContext::actingAs($outroTenant, function () {
            return FamiliaMaterial::create(['nome' => 'TUBULACAO']);
        });

        $this->assertNotNull($familiaOutroTenant->id);
        $this->assertSame(1, FamiliaMaterial::whereRaw('LOWER(nome) = ?', ['tubulacao'])->count());
        $this->assertNull(FamiliaMaterial::find($familiaOutroTenant->id));
    }

    // ---- M/N: cross-obra e cross-tenant continuam protegidos com o guard novo ----

    public function test_m_cross_obra_continua_protegido(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $outroDocumento = DocumentoEngenharia::create(['obra_id' => $outraObra->id, 'codigo' => 'X', 'descricao' => 'X']);
        $outraRevisao = $outroDocumento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $listaDeOutraObra = ListaEngenharia::create(['documento_engenharia_revisao_id' => $outraRevisao->id, 'tipo' => 'material', 'codigo' => 'LM-001']);

        $encontrada = ListaEngenharia::whereHas('revisao.documento', fn ($q) => $q->where('obra_id', $this->obra->id))->find($listaDeOutraObra->id);
        $this->assertNull($encontrada);
    }

    public function test_n_cross_tenant_continua_protegido(): void
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

    // ---- O/P: MICROAUDITORIA FINAL — atomicidade real de aplicar() sob
    // mudança de vigência NO MEIO do loop (Cenário B, distinto do teste F,
    // que só cobre a lista já histórica ANTES de aplicar() começar). ----

    /**
     * Teste O — `aplicar()` chamado SEM nenhuma transação envolvendo (o
     * mesmo jeito que o teste F/C/L3 já chamam direto, e a forma como um
     * futuro chamador que esqueça de embrulhar em transacaoSegura()
     * chamaria). Linha A é gravada com sucesso enquanto a lista ainda é
     * vigente; um listener dispara a criação de R2 (congelando a lista)
     * IMEDIATAMENTE depois — a linha B, processada em seguida no mesmo
     * loop, é bloqueada pelo Observer. Prova que, SEM transação externa,
     * a escrita A permanece persistida (aplicar() não é atômico sozinho).
     */
    public function test_o_sem_transacao_externa_escrita_anterior_permanece_apos_falha_no_meio_do_loop(): void
    {
        $r1 = $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lm1 = ListaEngenharia::create(['documento_engenharia_revisao_id' => $r1->id, 'tipo' => 'material', 'codigo' => 'LM-001']);

        $documento = $this->documento;
        ItemTakeOff::created(function (ItemTakeOff $item) use ($documento) {
            if ($item->codigo === 'A') {
                $documento->revisoes()->create(['revisao' => 'R2', 'data_emissao' => now(), 'descricao' => 'E2']);
            }
        });

        $importer = new TakeOffImporter();
        $linhas = [
            ['linha' => 2, 'codigo' => 'A', 'descricao' => 'A', 'unidade' => null, 'familia' => null, 'disciplina' => null, 'quantidade' => 10.0, 'observacoes' => null],
            ['linha' => 3, 'codigo' => 'B', 'descricao' => 'B', 'unidade' => null, 'familia' => null, 'disciplina' => null, 'quantidade' => 5.0, 'observacoes' => null],
        ];

        try {
            $importer->aplicar($linhas, $lm1, $this->user->id);
            $this->fail('Esperava ListaEngenhariaImutavelException na linha B.');
        } catch (ListaEngenhariaImutavelException $e) {
            // Sem transação externa: a linha A já foi commitada de fato —
            // aplicar() sozinho NÃO reverte escritas anteriores do próprio loop.
            $this->assertTrue(
                ItemTakeOff::where('lista_engenharia_id', $lm1->id)->where('codigo', 'A')->exists(),
                'Sem uma transação externa envolvendo aplicar(), a escrita da linha A deveria permanecer persistida mesmo após a linha B falhar — isso comprova que aplicar() não é atômico por si só, e que a atomicidade real depende inteiramente de quem chama.'
            );
            $this->assertFalse(ItemTakeOff::where('lista_engenharia_id', $lm1->id)->where('codigo', 'B')->exists());
        }
    }

    /**
     * Teste P — MESMO cenário do teste O, mas agora `aplicar()` é chamado
     * de dentro de `DB::transaction()` — reproduzindo fielmente o caminho
     * real de produção (`⚡take-off.blade.php::confirmarImportacao()` →
     * `transacaoSegura()` → `DB::transaction()` → `aplicar()`). Prova que,
     * QUANDO o chamador embrulha a chamada numa transação real (como o
     * único caminho de produção sempre faz), a escrita A É revertida
     * junto com a falha de B — atomicidade real, garantida pelo
     * mecanismo transacional do banco, nunca pelo Observer sozinho.
     */
    public function test_p_com_transacao_externa_escrita_anterior_e_revertida_junto_com_a_falha(): void
    {
        $r1 = $this->documento->revisoes()->create(['revisao' => 'R1', 'data_emissao' => now(), 'descricao' => 'E']);
        $lm1 = ListaEngenharia::create(['documento_engenharia_revisao_id' => $r1->id, 'tipo' => 'material', 'codigo' => 'LM-001']);

        $documento = $this->documento;
        ItemTakeOff::created(function (ItemTakeOff $item) use ($documento) {
            if ($item->codigo === 'A') {
                $documento->revisoes()->create(['revisao' => 'R2', 'data_emissao' => now(), 'descricao' => 'E2']);
            }
        });

        $importer = new TakeOffImporter();
        $linhas = [
            ['linha' => 2, 'codigo' => 'A', 'descricao' => 'A', 'unidade' => null, 'familia' => null, 'disciplina' => null, 'quantidade' => 10.0, 'observacoes' => null],
            ['linha' => 3, 'codigo' => 'B', 'descricao' => 'B', 'unidade' => null, 'familia' => null, 'disciplina' => null, 'quantidade' => 5.0, 'observacoes' => null],
        ];

        try {
            \Illuminate\Support\Facades\DB::transaction(function () use ($importer, $linhas, $lm1) {
                $importer->aplicar($linhas, $lm1, $this->user->id);
            });
            $this->fail('Esperava ListaEngenhariaImutavelException propagada através de DB::transaction().');
        } catch (ListaEngenhariaImutavelException $e) {
            // Com a transação real do chamador (o caminho de produção): a
            // escrita da linha A é revertida junto com a falha de B —
            // nunca fica "meio caminho andado".
            $this->assertFalse(
                ItemTakeOff::where('lista_engenharia_id', $lm1->id)->where('codigo', 'A')->exists(),
                'Dentro de DB::transaction() (o mesmo mecanismo que transacaoSegura() usa em produção), a escrita da linha A deveria ser revertida junto com a falha da linha B — essa é a fonte real da atomicidade, não o Observer.'
            );
            $this->assertFalse(ItemTakeOff::where('lista_engenharia_id', $lm1->id)->where('codigo', 'B')->exists());
        }
    }
}
