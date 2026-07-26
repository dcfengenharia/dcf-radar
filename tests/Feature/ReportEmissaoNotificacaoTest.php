<?php

namespace Tests\Feature;

use App\Models\CronogramaImportacao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Notifications\ReportEmitidoNotification;
use App\Services\ReportGerador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Cobre o achado desta fase: nenhum teste de emissão anterior tinha um
 * segundo usuário vinculado à obra, então `Report::emitir()` nunca
 * exercitava o caminho de `Notification::send()` (com destinatários
 * vazios, a chamada nem acontece). Aqui garantimos: (1) a notificação é
 * disparada pros destinatários certos, excluindo o autor; (2) uma falha
 * na notificação (ex.: broadcast/fila indisponível) nunca impede a
 * transição de status de valer — é efeito colateral best-effort.
 */
class ReportEmissaoNotificacaoTest extends TestCase
{
    use RefreshDatabase;

    private function gerador(): ReportGerador
    {
        return app(ReportGerador::class);
    }

    public function test_emitir_notifica_os_demais_usuarios_da_obra_exceto_o_autor(): void
    {
        Notification::fake();

        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $autor = User::factory()->create(['tenant_id' => $tenant->id]);
        $outroUsuario = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->vincularObra($obra, $autor, 'gerente_planejamento');
        $this->vincularObra($obra, $outroUsuario, 'encarregado');

        CronogramaImportacao::create([
            'tenant_id' => $tenant->id,
            'obra_id' => $obra->id,
            'data_status' => now(),
            'importado_em' => now(),
        ]);

        $this->actingAs($autor);

        $report = $this->gerador()->gerarRascunho($obra, $autor, [
            'periodo_referencia' => now()->toDateString(),
            'curvas' => [],
        ]);

        $this->gerador()->emitir($report, $autor);

        Notification::assertSentTo($outroUsuario, ReportEmitidoNotification::class);
        Notification::assertNotSentTo($autor, ReportEmitidoNotification::class);

        $report->refresh();
        $this->assertTrue($report->estaEmitido());
    }

    public function test_falha_na_notificacao_nao_impede_a_transicao_de_status(): void
    {
        $tenant = Tenant::factory()->create();
        $obra = Work::factory()->create(['tenant_id' => $tenant->id]);
        $autor = User::factory()->create(['tenant_id' => $tenant->id]);
        $outroUsuario = User::factory()->create(['tenant_id' => $tenant->id]);

        $this->vincularObra($obra, $autor, 'gerente_planejamento');
        $this->vincularObra($obra, $outroUsuario, 'encarregado');

        CronogramaImportacao::create([
            'tenant_id' => $tenant->id,
            'obra_id' => $obra->id,
            'data_status' => now(),
            'importado_em' => now(),
        ]);

        $this->actingAs($autor);

        $report = $this->gerador()->gerarRascunho($obra, $autor, [
            'periodo_referencia' => now()->toDateString(),
            'curvas' => [],
        ]);

        Notification::shouldReceive('send')->once()->andThrow(new \RuntimeException('Falha simulada de fila/broadcast'));

        $this->gerador()->emitir($report, $autor);

        $report->refresh();
        $this->assertTrue($report->estaEmitido());
        $this->assertSame($autor->id, $report->emitido_por);
        $this->assertNotNull($report->emitido_em);
    }
}
