<?php

namespace Tests\Feature;

use App\Enums\TipoCronogramaImportacao;
use App\Models\CronogramaImportacao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Fase 3, Etapa 5 — Histórico de Importações (partial compartilhado
 * historico-importacoes.blade.php), usado nas 3 páginas: ⚡cronograma.blade.php,
 * ⚡relatorio-importar-avanco.blade.php e ⚡obra-detalhe.blade.php (gestão).
 * Nunca inventa Score para importação antiga.
 */
class HistoricoImportacoesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($this->user);
        $this->obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $this->vincularObra($this->obra, $this->user, 'gerente_planejamento');
    }

    private function arquivoFixture(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, file_get_contents(__DIR__ . '/../Fixtures/' . $name));
    }

    // =====================================================================
    // APARECE NAS 3 PÁGINAS
    // =====================================================================

    public function test_historico_aparece_em_cronograma_apos_importar(): void
    {
        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->call('confirmar')
            ->assertSet('importado', true);

        // O nome persistido em `arquivo` é o nome do arquivo temp que o
        // Livewire gera no upload (basename($caminhoArquivo) dentro do
        // Job), nunca o nome original do arquivo enviado pelo usuário —
        // por isso lê o valor real gravado, em vez de assumir o nome da
        // fixture.
        $arquivo = CronogramaImportacao::first()->arquivo;

        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->assertSee('Histórico de Importações de Linha de Base')
            ->assertSee($arquivo);
    }

    public function test_historico_aparece_em_relatorio_importar_avanco_apos_importar(): void
    {
        // Avanço só atualiza atividades já casadas — precisa de Baseline antes.
        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->call('confirmar')
            ->assertSet('importado', true);

        Livewire::test('pages::radar.relatorio-importar-avanco', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->call('confirmar')
            ->assertSet('importado', true);

        // Filtra por tipo (não por "mais recente") — as duas importações
        // podem cair no mesmo segundo, tornando a ordenação por
        // importado_em ambígua entre elas.
        $arquivoAvanco = CronogramaImportacao::where('tipo', TipoCronogramaImportacao::Avanco)->first()->arquivo;

        Livewire::test('pages::radar.relatorio-importar-avanco', ['obra' => $this->obra])
            ->assertSee('Histórico de Importações de Avanço')
            ->assertSee($arquivoAvanco);
    }

    public function test_historico_aparece_em_obra_detalhe_apos_importar(): void
    {
        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->call('confirmar')
            ->assertSet('importado', true);

        $arquivo = CronogramaImportacao::first()->arquivo;

        Livewire::test('pages::gestao.obra-detalhe', ['obra' => $this->obra])
            ->set('abaAtiva', 'importacoes')
            ->assertSee($arquivo);
    }

    // =====================================================================
    // SCORE / FAIXA / COBERTURA NO HISTÓRICO
    // =====================================================================

    public function test_historico_mostra_score_faixa_e_cobertura_quando_disponiveis(): void
    {
        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->call('confirmar')
            ->assertSet('importado', true);

        $score = CronogramaImportacao::first()->healthCheck->scoreResultado();

        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->assertSee((string) $score->score)
            ->assertSee($score->faixa->label())
            ->assertSee($score->cobertura . '%')
            ->assertSee('Analisado');
    }

    public function test_historico_mostra_situacao_sem_analise_registrada_para_importacao_antiga(): void
    {
        // Simula um registro anterior à Fase 1 (Health Check) — sem
        // nenhuma linha em cronograma_importacao_health_checks.
        CronogramaImportacao::create([
            'obra_id' => $this->obra->id,
            'arquivo' => 'cronograma_legado.xml',
            'importado_em' => now()->subMonths(6),
        ]);

        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->assertSee('cronograma_legado.xml')
            ->assertSee('Sem análise registrada');
    }

    public function test_historico_nao_inventa_score_para_importacao_com_health_check_mas_sem_score(): void
    {
        $importacao = CronogramaImportacao::create([
            'obra_id' => $this->obra->id,
            'arquivo' => 'cronograma_pre_score.xml',
            'importado_em' => now()->subDays(10),
        ]);

        \App\Models\CronogramaImportacaoHealthCheck::create(
            ['cronograma_importacao_id' => $importacao->id]
            + \App\Models\CronogramaImportacaoHealthCheck::camposParaPersistir(['findings' => []])
        );

        $component = Livewire::test('pages::radar.cronograma', ['obra' => $this->obra]);

        $component->assertSee('cronograma_pre_score.xml')
            ->assertSee('Análise disponível — Score indisponível');
    }

    // =====================================================================
    // CLIQUE / NAVEGAÇÃO
    // =====================================================================

    public function test_linha_do_historico_aponta_para_a_rota_de_detalhe_correta(): void
    {
        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->call('confirmar')
            ->assertSet('importado', true);

        $importacao = CronogramaImportacao::first();
        $html = Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])->html();

        $this->assertStringContainsString(route('radar.importacoes.show', $importacao), $html);
    }

    // =====================================================================
    // PAGINAÇÃO
    // =====================================================================

    public function test_paginacao_aparece_quando_ha_mais_de_10_importacoes(): void
    {
        for ($i = 0; $i < 11; $i++) {
            CronogramaImportacao::create([
                'obra_id' => $this->obra->id,
                'arquivo' => "cronograma_{$i}.xml",
                'importado_em' => now()->subDays($i),
            ]);
        }

        $component = Livewire::test('pages::radar.cronograma', ['obra' => $this->obra]);

        // 11 registros com paginate(10) -> exatamente 10 na primeira página.
        $this->assertCount(10, $component->instance()->historicoImportacoes->items());
        $this->assertSame(11, $component->instance()->historicoImportacoes->total());
    }
}
