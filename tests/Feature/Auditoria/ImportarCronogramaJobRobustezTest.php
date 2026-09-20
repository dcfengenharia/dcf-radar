<?php

namespace Tests\Feature\Auditoria;

use App\Enums\TipoCronogramaImportacao;
use App\Jobs\ImportarCronogramaJob;
use App\Models\Tenant;
use App\Models\Work;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Auditoria Pré-Produção A2, Seções 5-7 — robustez de ImportarCronogramaJob:
 * ShouldBeUnique (contra double-delivery da fila, achado do mismatch
 * retry_after×timeout), failed() nunca marcando estado de erro numa
 * tentativa intermediária e nunca expondo mensagem técnica crua ao
 * usuário, e retry_after do Redis coerente com o maior timeout real.
 */
class ImportarCronogramaJobRobustezTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private Work $obra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
    }

    public function test_job_implementa_shouldbeunique(): void
    {
        $job = new ImportarCronogramaJob($this->obra, '/tmp/x.xml', null, TipoCronogramaImportacao::Baseline, 'tracking-1', null);

        $this->assertInstanceOf(ShouldBeUnique::class, $job);
    }

    public function test_uniqueid_usa_o_trackingid_quando_presente(): void
    {
        $job = new ImportarCronogramaJob($this->obra, '/tmp/x.xml', null, TipoCronogramaImportacao::Baseline, 'tracking-abc-123', null);

        $this->assertSame('tracking-abc-123', $job->uniqueId());
    }

    public function test_uniqueid_nunca_colide_entre_dispatches_distintos_sem_trackingid(): void
    {
        $job1 = new ImportarCronogramaJob($this->obra, '/tmp/x.xml', null, TipoCronogramaImportacao::Baseline, null, null);
        $job2 = new ImportarCronogramaJob($this->obra, '/tmp/y.xml', null, TipoCronogramaImportacao::Baseline, null, null);

        $this->assertNotSame('', $job1->uniqueId());
        $this->assertNotSame($job1->uniqueId(), $job2->uniqueId());
    }

    public function test_failed_marca_status_erro_com_mensagem_generica_nunca_a_mensagem_tecnica_crua(): void
    {
        $trackingId = 'tracking-falha-' . uniqid();
        $job = new ImportarCronogramaJob($this->obra, '/tmp/x.xml', null, TipoCronogramaImportacao::Baseline, $trackingId, null);

        // Simula uma exceção técnica real (poderia conter caminho de
        // arquivo, fragmento de SQL, etc.) — nunca deveria chegar ao
        // usuário verbatim (Seção 7: "não expor stack trace ao usuário").
        $job->failed(new \RuntimeException(
            'SQLSTATE[23000]: erro interno em /var/www/html/storage/app/tmp/segredo.xml linha 42'
        ));

        $status = Cache::get("cronograma-importacao-status:{$trackingId}");

        $this->assertNotNull($status);
        $this->assertSame('erro', $status['status']);
        $this->assertStringNotContainsString('SQLSTATE', $status['erro']);
        $this->assertStringNotContainsString('segredo.xml', $status['erro']);
        $this->assertStringNotContainsString('/var/www/html', $status['erro']);
    }

    public function test_failed_sem_trackingid_nunca_lanca_excecao(): void
    {
        $job = new ImportarCronogramaJob($this->obra, '/tmp/x.xml', null, TipoCronogramaImportacao::Baseline, null, null);

        // Não deve lançar nada — marcarStatus() já retorna cedo quando
        // trackingId é null (mesmo comportamento de sempre, só verificado
        // explicitamente aqui contra a nova failed()).
        $job->failed(new \RuntimeException('qualquer coisa'));

        $this->assertTrue(true);
    }

    public function test_retry_after_do_redis_e_maior_que_o_timeout_do_job_mais_longo_do_sistema(): void
    {
        $retryAfter = config('queue.connections.redis.retry_after');
        $timeoutImportacao = (new ImportarCronogramaJob($this->obra, '/tmp/x.xml', null))->timeout;

        $this->assertSame(660, $retryAfter);
        $this->assertSame(600, $timeoutImportacao);
        $this->assertGreaterThan(
            $timeoutImportacao,
            $retryAfter,
            'retry_after precisa ser MAIOR que o timeout do Job mais longo do sistema — senão o Redis reentrega a mesma importação pra outro worker enquanto a primeira ainda está legitimamente rodando.'
        );
    }
}
