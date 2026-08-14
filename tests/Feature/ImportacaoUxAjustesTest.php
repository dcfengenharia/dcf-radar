<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ajustes de UX identificados em teste real da Fase 2 (sem alteração de
 * nenhuma regra de negócio/Health Check/parser/Engine/Score):
 *
 * 1. Botão "Analisar" não pode ficar clicável antes do upload ser
 *    reconhecido pelo Livewire — `hasFile` passou a ser controlado
 *    exclusivamente pelos eventos `livewire-upload-*`, nunca pelo
 *    `x-on:change` bruto do input (race condition corrigida em
 *    ⚡cronograma.blade.php; mecanismo inteiro adicionado do zero em
 *    ⚡relatorio-importar-avanco.blade.php, que não tinha nenhum controle).
 * 2. Checklist de etapas reais durante a análise (substituiu a barra de
 *    progresso percentual, que era uma estimativa inventada) nas duas
 *    telas.
 * 3. Accordion de findings do Health Check corrigido pra alternar
 *    (abrir/fechar) de verdade — trocado o `data-bs-toggle="collapse"`
 *    nativo do Bootstrap (estado próprio em JS, frágil dentro de um
 *    componente Livewire) por um `x-data="{ aberto: false }"` do Alpine
 *    com toggle explícito.
 *
 * Como Livewire::test() não executa JavaScript/Alpine, os itens que só
 * podem ser verificados via interação real do navegador (abrir/fechar o
 * accordion clicando, ordem de disparo dos eventos livewire-upload-*) NÃO
 * são cobertos aqui — são verificados na marcação (markup) renderizada,
 * que é a fonte da verdade do que o navegador vai executar.
 */
class ImportacaoUxAjustesTest extends TestCase
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
    }

    private function arquivoFixture(string $name): UploadedFile
    {
        $conteudo = file_get_contents(__DIR__ . '/../Fixtures/' . $name);

        return UploadedFile::fake()->createWithContent($name, $conteudo);
    }

    // -------------------------------------------------------------------
    // 1. Estado do botão "Analisar" — ⚡cronograma.blade.php
    // -------------------------------------------------------------------

    public function test_cronograma_botao_analisar_reflete_hasfile_false_sem_arquivo(): void
    {
        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->assertSee("hasFile: false", false);
    }

    public function test_cronograma_botao_analisar_reflete_hasfile_true_com_arquivo_reconhecido(): void
    {
        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->assertSee("hasFile: true", false);
    }

    public function test_cronograma_hasfile_nao_e_mais_resetado_pelo_change_bruto_do_input(): void
    {
        // A causa raiz da corrida era resetar hasFile dentro do x-on:change
        // do input, competindo com o listener interno do Livewire no mesmo
        // evento 'change'. Confirma que esse reset foi removido dali — só
        // acontece agora nos eventos livewire-upload-* (fonte de verdade
        // do próprio Livewire, sem depender de ordem entre dois listeners
        // do mesmo evento nativo).
        $html = Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])->html();

        $this->assertStringContainsString('livewire-upload-start="uploading = true; hasFile = false;', $html);

        // Isola o trecho do x-on:change do <input type="file"> e confirma
        // que ele só lê o tamanho do arquivo — nunca mexe em hasFile.
        preg_match('/x-on:change="([^"]*)"/s', $html, $matches);
        $this->assertNotEmpty($matches, 'x-on:change do input não encontrado no HTML renderizado.');
        $this->assertStringNotContainsString('hasFile', $matches[1]);
        $this->assertStringContainsString('tamanhoSelecionadoMb', $matches[1]);
    }

    // -------------------------------------------------------------------
    // 1. Estado do botão "Analisar" — ⚡relatorio-importar-avanco.blade.php
    //    (não tinha NENHUM controle antes deste ajuste)
    // -------------------------------------------------------------------

    public function test_avanco_botao_analisar_reflete_hasfile_false_sem_arquivo(): void
    {
        Livewire::test('pages::radar.relatorio-importar-avanco', ['obra' => $this->obra])
            ->assertSee("hasFile: false", false);
    }

    public function test_avanco_botao_analisar_reflete_hasfile_true_com_arquivo_reconhecido(): void
    {
        Livewire::test('pages::radar.relatorio-importar-avanco', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->assertSee("hasFile: true", false);
    }

    public function test_avanco_botao_analisar_tem_atributo_disabled_amarrado_a_hasfile(): void
    {
        $html = Livewire::test('pages::radar.relatorio-importar-avanco', ['obra' => $this->obra])->html();

        $this->assertStringContainsString(':disabled="!hasFile || uploading"', $html);
    }

    // -------------------------------------------------------------------
    // 2. Checklist de etapas reais durante a análise
    // -------------------------------------------------------------------

    public function test_cronograma_checklist_de_etapas_reflete_o_pipeline_real(): void
    {
        $html = Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])->html();

        foreach ([
            'Recebendo e abrindo o arquivo XML',
            'Montando predecessoras e sucessoras',
            'Avaliando a estrutura da rede do cronograma',
            'Verificando a lógica do cronograma',
            'Analisando as folgas (slack)',
            'Executando o Health Check',
            'Consolidando o resultado da prévia',
        ] as $etapa) {
            $this->assertStringContainsString($etapa, $html);
        }

        // A barra de progresso percentual (estimativa inventada) foi removida.
        $this->assertStringNotContainsString("progresso + '%'", $html);
    }

    public function test_avanco_checklist_de_etapas_reflete_o_pipeline_real(): void
    {
        $html = Livewire::test('pages::radar.relatorio-importar-avanco', ['obra' => $this->obra])->html();

        foreach ([
            'Recebendo e abrindo o arquivo XML',
            'Montando predecessoras e sucessoras',
            'Executando o Health Check',
            'Consolidando o resultado da prévia',
        ] as $etapa) {
            $this->assertStringContainsString($etapa, $html);
        }
    }

    // -------------------------------------------------------------------
    // Dupla submissão — o formulário inteiro (incl. botão) some da tela
    // enquanto analisar() está em andamento, em vez de só desabilitar.
    // -------------------------------------------------------------------

    public function test_cronograma_formulario_fica_atras_de_wire_loading_remove(): void
    {
        $html = Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])->html();

        $this->assertStringContainsString('wire:loading.remove wire:target="analisar"', $html);
        $this->assertStringContainsString('wire:loading wire:target="analisar"', $html);
    }

    public function test_avanco_formulario_fica_atras_de_wire_loading_remove(): void
    {
        $html = Livewire::test('pages::radar.relatorio-importar-avanco', ['obra' => $this->obra])->html();

        $this->assertStringContainsString('wire:loading.remove wire:target="analisar"', $html);
        $this->assertStringContainsString('wire:loading wire:target="analisar"', $html);
    }

    // -------------------------------------------------------------------
    // 3. Accordion do Health Check — toggle via Alpine, não mais via
    //    data-bs-toggle="collapse" nativo do Bootstrap.
    // -------------------------------------------------------------------

    public function test_accordion_de_findings_usa_toggle_explicito_do_alpine(): void
    {
        // Ciclo 4 — cronograma_sample.xml só dispara regras de Execução
        // (PROG-001/WORK-004), filtradas na Baseline (Ciclo 2); sem nenhum
        // finding, o accordion nem chega a renderizar. Troca pra uma fixture
        // que dispara regras de Planejamento reais sob Baseline.
        $html = Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_fase2b3_slack.xml'))
            ->call('analisar')
            ->html();

        $this->assertStringContainsString('x-data="{ aberto: false }"', $html);
        $this->assertStringContainsString('@click="aberto = !aberto"', $html);
        $this->assertStringNotContainsString('data-bs-toggle="collapse"', $html);
    }

    public function test_accordion_de_findings_na_tela_de_avanco_tambem_usa_toggle_do_alpine(): void
    {
        // A importação de Avanço só ATUALIZA atividades já casadas por
        // external_uid (nunca cria) — precisa existir a Linha de Base antes,
        // senão todas as tarefas do XML viram "ignoradas" e o Health Check
        // não tem nenhuma atividade pra avaliar (0 findings), mesmo usando
        // uma fixture que dispara alertas pelo caminho de Baseline. Mesmo
        // setup já usado em CronogramaHealthCheckTest::
        // test_importacao_de_avanco_calcula_e_persiste_health_check().
        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->call('confirmar')
            ->assertSet('importado', true);

        $html = Livewire::test('pages::radar.relatorio-importar-avanco', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_sample.xml'))
            ->call('analisar')
            ->html();

        $this->assertStringContainsString('x-data="{ aberto: false }"', $html);
        $this->assertStringContainsString('@click="aberto = !aberto"', $html);
        $this->assertStringNotContainsString('data-bs-toggle="collapse"', $html);
    }

    // -------------------------------------------------------------------
    // Regressão — o fluxo de análise/confirmação em si continua intacto
    // (nenhuma regra de negócio, DTO, parser ou Engine foi tocado).
    // -------------------------------------------------------------------

    public function test_analisar_e_confirmar_continuam_funcionando_normalmente_apos_os_ajustes_de_ux(): void
    {
        Livewire::test('pages::radar.cronograma', ['obra' => $this->obra])
            ->set('arquivoTemp', $this->arquivoFixture('cronograma_health_check_sem_alertas.xml'))
            ->call('analisar')
            ->assertSet('emPrevia', true)
            ->call('confirmar')
            ->assertSet('importado', true);
    }
}
