<?php

namespace Tests\Feature;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Imports\Contracts\ImportadorCronograma;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 2B.2A do Health Check — valida SÓ a leitura aditiva do modo de
 * agendamento (<Manual>, MSPDI) pro DTO TarefaImportada. Nenhuma regra de
 * Health Check é avaliada aqui (Fase 2B.2B, ainda não implementada) — só
 * confere que MsProjectImporter::analisar() está extraindo o campo certo,
 * sem tocar em nada de HH/baseline/realizado/predecessoras/estrutura.
 */
class MsProjectImporterModoAgendamentoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Work $obra;
    private ImportadorCronograma $importer;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $tenant->id]);
        $this->actingAs($this->user);
        $this->obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $this->importer = app(ImportadorCronograma::class);
    }

    private function fixture(string $name): string
    {
        return __DIR__ . '/../Fixtures/' . $name;
    }

    private function plano(): PlanoImportacao
    {
        return $this->importer->analisar($this->fixture('cronograma_fase2b2a_modo_agendamento.xml'), $this->obra);
    }

    private function porUid(PlanoImportacao $plano, string $uid): TarefaImportada
    {
        foreach ([...$plano->criar, ...$plano->pacotes] as $tarefa) {
            if ($tarefa->uid === $uid) {
                return $tarefa;
            }
        }

        $this->fail("Tarefa UID={$uid} não encontrada no plano.");
    }

    public function test_manual_1_e_normalizado_como_manual(): void
    {
        $t = $this->porUid($this->plano(), '1');

        $this->assertTrue($t->agendamentoManual);
        $this->assertSame('1', $t->agendamentoManualBruto);
    }

    public function test_manual_0_e_normalizado_como_automatica(): void
    {
        $t = $this->porUid($this->plano(), '2');

        $this->assertFalse($t->agendamentoManual);
        $this->assertSame('0', $t->agendamentoManualBruto);
    }

    public function test_campo_manual_ausente_nao_quebra_a_importacao_e_fica_desconhecido(): void
    {
        $t = $this->porUid($this->plano(), '3');

        $this->assertNull($t->agendamentoManual);
        $this->assertNull($t->agendamentoManualBruto);
    }

    public function test_manual_true_textual_e_normalizado_como_manual(): void
    {
        $t = $this->porUid($this->plano(), '4');

        $this->assertTrue($t->agendamentoManual);
        $this->assertSame('true', $t->agendamentoManualBruto);
    }

    public function test_manual_false_textual_e_normalizado_como_automatica(): void
    {
        $t = $this->porUid($this->plano(), '5');

        $this->assertFalse($t->agendamentoManual);
        $this->assertSame('false', $t->agendamentoManualBruto);
    }

    public function test_valor_inesperado_nunca_e_interpretado_vira_desconhecido_mas_preserva_o_bruto(): void
    {
        $t = $this->porUid($this->plano(), '6');

        $this->assertNull($t->agendamentoManual);
        $this->assertSame('2', $t->agendamentoManualBruto);
    }

    public function test_campos_ja_existentes_continuam_intactos(): void
    {
        $t = $this->porUid($this->plano(), '1');

        $this->assertSame('Tarefa Manual (Manual=1)', $t->nome);
        $this->assertFalse($t->isSummary);
        $this->assertSame('2024-01-01', $t->dataInicio->toDateString());
        $this->assertSame('2024-01-07', $t->dataTermino->toDateString());
    }
}
