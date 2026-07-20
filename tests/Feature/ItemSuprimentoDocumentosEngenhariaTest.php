<?php

namespace Tests\Feature;

use App\Models\DocumentoEngenharia;
use App\Models\FluxoSuprimento;
use App\Models\ItemSuprimento;
use App\Models\PacoteEngenharia;
use App\Models\StatusDocumento;
use App\Models\Tenant;
use App\Models\Work;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O vínculo de documentos de engenharia é direto no documento (N:N via
 * item_suprimento_documentos), não no PacoteEngenharia — por isso um
 * mesmo item pode juntar documentos de VÁRIOS pacotes diferentes. Estes
 * testes confirmam que %concluído/próxima data limite são calculados
 * sobre esse conjunto agregado, não sobre um pacote isolado (que já tem
 * sua própria versão desses cálculos, cobertos noutro teste).
 */
class ItemSuprimentoDocumentosEngenhariaTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;
    private StatusDocumento $statusConcluido;
    private StatusDocumento $statusPendente;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->statusConcluido = StatusDocumento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Concluído',
            'conclusivo' => true,
        ]);
        $this->statusPendente = StatusDocumento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Em Elaboração',
            'conclusivo' => false,
        ]);
    }

    private function criarItem(): ItemSuprimento
    {
        $fluxo = FluxoSuprimento::create(['tenant_id' => $this->tenant->id, 'nome' => 'Fluxo Teste']);

        return ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fluxo_suprimento_id' => $fluxo->id,
            'nome' => 'Item de Teste',
        ]);
    }

    private function criarDocumento(PacoteEngenharia $pacote, string $descricao, StatusDocumento $status, ?string $dataPlanejada = null): DocumentoEngenharia
    {
        $documento = DocumentoEngenharia::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'pacote_engenharia_id' => $pacote->id,
            'codigo' => strtoupper(str_replace(' ', '-', $descricao)),
            'descricao' => $descricao,
            'data_planejada' => $dataPlanejada,
        ]);

        // Status agora é da emissão (revisão), não mais do documento.
        $documento->revisoes()->create([
            'tenant_id' => $this->tenant->id,
            'revisao' => 'R0',
            'descricao' => 'Emissão inicial',
            'status_documento_id' => $status->id,
        ]);

        return $documento;
    }

    public function test_percentual_e_proxima_data_limite_agregam_documentos_de_pacotes_diferentes(): void
    {
        $pacoteEstrutural = PacoteEngenharia::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Estrutural']);
        $pacoteHidraulico = PacoteEngenharia::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Hidráulico']);

        $docConcluidoEstrutural = $this->criarDocumento($pacoteEstrutural, 'Projeto Estrutural', $this->statusConcluido, '2026-06-01');
        $docPendenteEstrutural = $this->criarDocumento($pacoteEstrutural, 'Memorial de Cálculo', $this->statusPendente, '2026-08-15');
        $docPendenteHidraulico = $this->criarDocumento($pacoteHidraulico, 'Projeto Hidráulico', $this->statusPendente, '2026-07-20');

        $item = $this->criarItem();
        TenantContext::actingAs($this->tenant, function () use ($item, $docConcluidoEstrutural, $docPendenteEstrutural, $docPendenteHidraulico) {
            $item->documentosEngenharia()->attach([$docConcluidoEstrutural->id, $docPendenteEstrutural->id, $docPendenteHidraulico->id]);
        });
        $item = $item->fresh(['documentosEngenharia.latestRevisao.statusDocumento']);

        // 1 de 3 concluído = 33.33%, arredondado a 2 casas.
        $this->assertSame(33.33, $item->percentualEngenhariaConcluida());

        // Maior data_planejada entre os NÃO concluídos (exclui o Estrutural
        // já concluído em 01/06): 15/08 (Memorial) > 20/07 (Hidráulico).
        $this->assertTrue($item->proximaDataLimiteEngenharia()->isSameDay(\Carbon\Carbon::parse('2026-08-15')));
    }

    public function test_percentual_zero_quando_item_nao_tem_documento_vinculado(): void
    {
        $item = $this->criarItem();

        $this->assertSame(0.0, $item->percentualEngenhariaConcluida());
        $this->assertNull($item->proximaDataLimiteEngenharia());
    }

    public function test_percentual_cem_e_proxima_data_nula_quando_todos_concluidos(): void
    {
        $pacote = PacoteEngenharia::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Pacote Único']);
        $doc1 = $this->criarDocumento($pacote, 'Doc 1', $this->statusConcluido, '2026-05-01');
        $doc2 = $this->criarDocumento($pacote, 'Doc 2', $this->statusConcluido, '2026-05-10');

        $item = $this->criarItem();
        TenantContext::actingAs($this->tenant, function () use ($item, $doc1, $doc2) {
            $item->documentosEngenharia()->attach([$doc1->id, $doc2->id]);
        });
        $item = $item->fresh(['documentosEngenharia.latestRevisao.statusDocumento']);

        $this->assertSame(100.0, $item->percentualEngenhariaConcluida());
        $this->assertNull($item->proximaDataLimiteEngenharia());
    }

    public function test_documento_sem_data_planejada_e_ignorado_na_proxima_data_limite(): void
    {
        $pacote = PacoteEngenharia::create(['tenant_id' => $this->tenant->id, 'obra_id' => $this->obra->id, 'nome' => 'Pacote']);
        $docSemData = $this->criarDocumento($pacote, 'Doc Sem Data', $this->statusPendente, null);
        $docComData = $this->criarDocumento($pacote, 'Doc Com Data', $this->statusPendente, '2026-09-01');

        $item = $this->criarItem();
        TenantContext::actingAs($this->tenant, function () use ($item, $docSemData, $docComData) {
            $item->documentosEngenharia()->attach([$docSemData->id, $docComData->id]);
        });
        $item = $item->fresh(['documentosEngenharia.latestRevisao.statusDocumento']);

        $this->assertTrue($item->proximaDataLimiteEngenharia()->isSameDay(\Carbon\Carbon::parse('2026-09-01')));
    }
}
