<?php

namespace Tests\Feature;

use App\Enums\Papel;
use App\Models\DocumentoEngenharia;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Cobertura dedicada do histórico de reprogramações — a Data de Previsão
 * de Emissão de um documento é editável, mas toda vez que ela muda depois
 * de já ter sido informada, isso vira um registro imutável (data anterior
 * → nova, quem, quando), usado pro badge de notificação na LD.
 */
class DocumentoEngenhariaReprogramacaoTest extends TestCase
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
        $this->vincularObra($this->obra, $this->user, Papel::Admin->value);
        $this->actingAs($this->user);
    }

    private function editarPrevisao(DocumentoEngenharia $documento, string $novaData): void
    {
        Livewire::test('pages::engenharia.documentos-engenharia')
            ->set('obraId', $this->obra->id)
            ->call('editarDocumento', $documento->id)
            ->set('dataPrevistaNovo', $novaData)
            ->call('salvarDocumento')
            ->assertHasNoErrors();
    }

    public function test_historico_mantem_ordem_e_valores_corretos_apos_varias_reprogramacoes(): void
    {
        $documento = DocumentoEngenharia::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'codigo' => 'DOC-HIST',
            'descricao' => 'Documento',
            'data_planejada' => '2026-07-01',
        ]);

        $this->editarPrevisao($documento, '2026-08-01');
        $this->editarPrevisao($documento, '2026-09-15');

        $documento = $documento->fresh(['reprogramacoes']);
        $this->assertSame(2, $documento->reprogramacoes->count());

        // orderByDesc('created_at') — a mais recente vem primeiro.
        $maisRecente = $documento->reprogramacoes->first();
        $this->assertSame('2026-08-01', $maisRecente->data_anterior->toDateString());
        $this->assertSame('2026-09-15', $maisRecente->data_nova->toDateString());

        $primeira = $documento->reprogramacoes->last();
        $this->assertSame('2026-07-01', $primeira->data_anterior->toDateString());
        $this->assertSame('2026-08-01', $primeira->data_nova->toDateString());

        $this->assertSame('2026-09-15', $documento->data_planejada->toDateString());
    }

    public function test_limpar_previsao_tambem_registra_reprogramacao_com_data_nova_nula(): void
    {
        $documento = DocumentoEngenharia::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'codigo' => 'DOC-LIMPA',
            'descricao' => 'Documento',
            'data_planejada' => '2026-07-01',
        ]);

        Livewire::test('pages::engenharia.documentos-engenharia')
            ->set('obraId', $this->obra->id)
            ->call('editarDocumento', $documento->id)
            ->set('dataPrevistaNovo', '')
            ->call('salvarDocumento')
            ->assertHasNoErrors();

        $documento = $documento->fresh(['reprogramacoes']);
        $this->assertNull($documento->data_planejada);
        $this->assertSame(1, $documento->reprogramacoes->count());
        $this->assertSame('2026-07-01', $documento->reprogramacoes->first()->data_anterior->toDateString());
        $this->assertNull($documento->reprogramacoes->first()->data_nova);
    }

    public function test_nao_gera_reprogramacao_quando_data_nao_muda(): void
    {
        $documento = DocumentoEngenharia::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'codigo' => 'DOC-SEM-MUDANCA',
            'descricao' => 'Documento',
            'data_planejada' => '2026-07-01',
        ]);

        $this->editarPrevisao($documento, '2026-07-01');

        $this->assertSame(0, $documento->fresh()->reprogramacoes()->count());
    }

    public function test_criacao_do_documento_nao_gera_reprogramacao(): void
    {
        Livewire::test('pages::engenharia.documentos-engenharia')
            ->set('obraId', $this->obra->id)
            ->call('abrirCriarDocumento')
            ->set('codigoNovo', 'DOC-NOVO')
            ->set('descricaoNovo', 'Documento Novo')
            ->set('dataPrevistaNovo', '2026-08-01')
            ->call('salvarDocumento')
            ->assertHasNoErrors();

        $documento = DocumentoEngenharia::where('codigo', 'DOC-NOVO')->firstOrFail();
        $this->assertSame(0, $documento->reprogramacoes()->count());
    }

    public function test_estaAtrasado_verdadeiro_so_quando_sem_emissao_e_previsao_vencida(): void
    {
        $atrasado = DocumentoEngenharia::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'codigo' => 'DOC-ATR',
            'descricao' => 'Documento',
            'data_planejada' => now()->subDay()->toDateString(),
        ]);
        $this->assertTrue($atrasado->estaAtrasado());

        $emitido = DocumentoEngenharia::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'codigo' => 'DOC-EMIT',
            'descricao' => 'Documento',
            'data_planejada' => now()->subDay()->toDateString(),
        ]);
        $emitido->revisoes()->create(['tenant_id' => $this->tenant->id, 'revisao' => 'R0', 'descricao' => 'Emissão']);
        $this->assertFalse($emitido->estaAtrasado());

        $noPrazo = DocumentoEngenharia::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'codigo' => 'DOC-PRAZO',
            'descricao' => 'Documento',
            'data_planejada' => now()->addDay()->toDateString(),
        ]);
        $this->assertFalse($noPrazo->estaAtrasado());
    }
}
