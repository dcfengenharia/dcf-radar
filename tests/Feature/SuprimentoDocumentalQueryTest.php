<?php

namespace Tests\Feature;

use App\Actions\Engenharia\AlterarLiberacaoRevisaoDocumento;
use App\Enums\Papel;
use App\Models\DocumentoEngenharia;
use App\Models\ItemSuprimento;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Support\Engenharia\SuprimentoDocumentalQuery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ciclo 22, Etapa 22.4.CORREÇÃO — teste permanente pro Achado B da
 * auditoria 22.4: `SuprimentoDocumentalQuery::pacotesBloqueadosPorDocumento()`
 * tinha eager-load insuficiente pra `DocumentoEngenharia::motivoLiberacao()`
 * (precisa de `latestRevisao.ultimaLiberacao`, nunca carregado antes desta
 * correção) — reproduzível com múltiplos Pacotes/documentos na mesma obra
 * (mesma condição empírica que expôs o bug na auditoria).
 */
class SuprimentoDocumentalQueryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $this->user, Papel::GerentePlanejamento->value);
        $this->actingAs($this->user);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{pacote: ItemSuprimento, doc: DocumentoEngenharia} */
    private function cenario(bool $liberar = false): array
    {
        $doc = DocumentoEngenharia::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'codigo' => 'DOC-' . uniqid(), 'descricao' => 'x']);
        $rev = $doc->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => 'R1', 'descricao' => 'x'])->fresh();
        if ($liberar) {
            (new AlterarLiberacaoRevisaoDocumento())->liberar($rev, $this->user);
        }
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote ' . uniqid()]);
        $pacote->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        return compact('pacote', 'doc');
    }

    /**
     * Cenário real da auditoria: múltiplos Pacotes/documentos na mesma
     * obra, `motivoLiberacao()` chamado dentro do `contexto` — com
     * lazy loading proibido (padrão de todo o projeto fora de
     * produção). Antes da correção, isso lançava
     * `LazyLoadingViolationException`; depois, resolve normalmente.
     * Não valida só "não lançou" — valida o CONTEÚDO (documento/pacote/
     * motivo) de cada fato retornado.
     */
    public function test_multiplos_pacotes_e_documentos_nao_liberados_nunca_lanca_lazy_loading(): void
    {
        $cenario1 = $this->cenario();
        $cenario2 = $this->cenario();
        $cenario3 = $this->cenario();

        $fatos = SuprimentoDocumentalQuery::pacotesBloqueadosPorDocumento($this->obra);

        $this->assertCount(3, $fatos);

        foreach ([$cenario1, $cenario2, $cenario3] as $cenario) {
            $fato = $fatos->first(fn ($f) => $f->entidadeId === $cenario['pacote']->id);
            $this->assertNotNull($fato, "fato do pacote {$cenario['pacote']->id} deveria existir.");
            $this->assertSame('suprimento_bloqueado_por_documento', $fato->tipo);
            $this->assertSame($cenario['doc']->id, $fato->contexto['documento_id']);
            $this->assertSame($cenario['pacote']->id, $fato->contexto['pacote_id']);
            $this->assertSame('revisao_nao_liberada', $fato->contexto['motivo_liberacao']);
            $this->assertStringContainsString($cenario['doc']->codigo, $fato->descricao);
            $this->assertStringContainsString($cenario['pacote']->nome, $fato->descricao);
        }
    }

    public function test_documento_liberado_nunca_gera_fato_e_nunca_lanca_excecao(): void
    {
        $this->cenario(liberar: true);

        $fatos = SuprimentoDocumentalQuery::pacotesBloqueadosPorDocumento($this->obra);

        $this->assertCount(0, $fatos);
    }

    public function test_documento_sem_nenhuma_revisao_produz_motivo_sem_revisao(): void
    {
        $pacote = ItemSuprimento::create(['obra_id' => $this->obra->id, 'nome' => 'Pacote Sem Revisao']);
        $doc = DocumentoEngenharia::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'codigo' => 'DOC-SR', 'descricao' => 'x']);
        $pacote->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);

        $fatos = SuprimentoDocumentalQuery::pacotesBloqueadosPorDocumento($this->obra);

        $this->assertCount(1, $fatos);
        $this->assertSame('sem_revisao', $fatos->first()->contexto['motivo_liberacao']);
    }

    /**
     * Convergência (Seção 6) — nunca duplica a fórmula de
     * `motivoLiberacao()` manualmente: compara o `motivo_liberacao` já
     * embutido no fato contra uma chamada FRESCA e totalmente carregada
     * do MESMO método, sobre o MESMO documento.
     */
    public function test_convergencia_motivo_liberacao_bate_com_chamada_direta_totalmente_carregada(): void
    {
        ['doc' => $doc] = $this->cenario();

        $fatos = SuprimentoDocumentalQuery::pacotesBloqueadosPorDocumento($this->obra);
        $motivoDoFato = $fatos->first()->contexto['motivo_liberacao'];

        $docCarregado = DocumentoEngenharia::with('latestRevisao.ultimaLiberacao')->findOrFail($doc->id);
        $motivoAutoritativo = $docCarregado->motivoLiberacao();

        $this->assertSame($motivoAutoritativo, $motivoDoFato);
        $this->assertSame($doc->revisaoVigente()->estaLiberadaParaConstrucao(), $docCarregado->revisaoVigente()->estaLiberadaParaConstrucao());
    }

    /**
     * Performance (Seção 7) — O(1), nunca cresce linearmente com o
     * número de Pacotes/documentos.
     */
    public function test_performance_delta_10_100_pacotes_nunca_escala_linearmente(): void
    {
        $medir = function (int $n): int {
            $obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
            for ($i = 0; $i < $n; $i++) {
                $doc = DocumentoEngenharia::create(['tenant_id' => $this->tenant->id, 'obra_id' => $obra->id, 'codigo' => 'DOC-' . uniqid(), 'descricao' => 'x']);
                $doc->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => 'R1', 'descricao' => 'x']);
                $pacote = ItemSuprimento::create(['obra_id' => $obra->id, 'nome' => 'Pacote ' . uniqid()]);
                $pacote->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);
            }

            DB::enableQueryLog();
            SuprimentoDocumentalQuery::pacotesBloqueadosPorDocumento($obra);
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();
            DB::flushQueryLog();

            return $count;
        };

        $q10 = $medir(10);
        $q100 = $medir(100);

        fwrite(STDERR, "\n[DELTA SuprimentoDocumentalQuery] 10 pacotes -> {$q10} queries | 100 pacotes -> {$q100} queries\n");

        $this->assertLessThan($q10 * 3, $q100, 'Query count não pode escalar proporcionalmente ao número de Pacotes/documentos.');
    }

    public function test_performance_delta_500_pacotes_se_viavel(): void
    {
        $obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        for ($i = 0; $i < 500; $i++) {
            $doc = DocumentoEngenharia::create(['tenant_id' => $this->tenant->id, 'obra_id' => $obra->id, 'codigo' => 'DOC-' . uniqid(), 'descricao' => 'x']);
            $doc->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => 'R1', 'descricao' => 'x']);
            $pacote = ItemSuprimento::create(['obra_id' => $obra->id, 'nome' => 'Pacote ' . uniqid()]);
            $pacote->documentosEngenharia()->attach($doc->id, ['tenant_id' => $this->tenant->id]);
        }

        DB::enableQueryLog();
        $inicio = microtime(true);
        $fatos = SuprimentoDocumentalQuery::pacotesBloqueadosPorDocumento($obra);
        $tempo = (microtime(true) - $inicio) * 1000;
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
        DB::flushQueryLog();

        fwrite(STDERR, "\n[DELTA SuprimentoDocumentalQuery] 500 pacotes -> {$count} queries, {$tempo}ms\n");

        $this->assertCount(500, $fatos);
        $this->assertLessThan(30, $count, '500 pacotes ainda deveria ser um número pequeno e fixo de queries.');
    }
}
