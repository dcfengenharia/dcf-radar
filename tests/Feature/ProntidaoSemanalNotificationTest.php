<?php

namespace Tests\Feature;

use App\Models\Atividade;
use App\Models\Restricao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use App\Notifications\ProntidaoSemanalNotification;
use App\Services\DigestProntidao;
use App\Support\CentralProntidao\ResumoDigestProntidao;
use App\Support\CentralProntidao\SnapshotDigestProntidao;
use App\Support\CentralProntidao\StatusOperacionalProntidao;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Ciclo 16, Etapa A.3 — Notification do Digest Semanal de Prontidão.
 * Testa exclusivamente a Notification (construída direto via `new`, nunca
 * `->notify()`/`Notification::fake()`, já que não existe nenhum ponto de
 * disparo em produção ainda — isso fica pra A.4) e sua redução de
 * payload a partir de um `ResumoDigestProntidao` real, produzido pelo
 * `DigestProntidao::consolidar()` já aprovado na A.2.
 */
class ProntidaoSemanalNotificationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private DigestProntidao $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Obra Teste Digest']);
        $this->service = app(DigestProntidao::class);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function criarAtividade(array $overrides = []): Atividade
    {
        return Atividade::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
        ], $overrides));
    }

    private function tornarNaoPronta(Atividade $atividade): void
    {
        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'bloqueante' => true,
        ]);
    }

    private function tornarAtencao(Atividade $atividade): void
    {
        Restricao::factory()->create([
            'tenant_id' => $this->tenant->id,
            'atividade_id' => $atividade->id,
            'bloqueante' => false,
        ]);
    }

    private function resumoComNAtividadesProblematicas(int $n): ResumoDigestProntidao
    {
        for ($i = 0; $i < $n; $i++) {
            $at = $this->criarAtividade([
                'external_uid' => "notif-{$i}",
                'inicio_planejado' => now()->addDays(2 + $i % 25),
                'codigo_cronograma' => "1." . ($i + 1),
            ]);
            $i % 2 === 0 ? $this->tornarNaoPronta($at) : $this->tornarAtencao($at);
        }

        return $this->service->consolidar($this->obra);
    }

    // =========================================================================
    // 1) Canais
    // =========================================================================

    public function test_via_retorna_somente_database_e_broadcast(): void
    {
        $resumo = $this->resumoComNAtividadesProblematicas(1);
        $notification = new ProntidaoSemanalNotification($resumo);

        $canais = $notification->via($this->user);

        $this->assertSame(['database', 'broadcast'], $canais);
        $this->assertNotContains('mail', $canais);
        $this->assertFalse(method_exists($notification, 'toMail'), 'Não deve existir toMail() — canal mail nunca deve ser usado');
        $this->assertFalse(method_exists($notification, 'toWhatsApp'), 'Não deve existir toWhatsApp() — ZApiChannel nunca deve ser usado');
    }

    // =========================================================================
    // 2) Snapshot reduzido — prova concreta
    // =========================================================================

    public function test_notification_nao_mantem_arvore_completa_de_atividades_problematicas(): void
    {
        $resumo = $this->resumoComNAtividadesProblematicas(50);
        $this->assertSame(50, count($resumo->atividadesProblematicas), 'Pré-condição: o resumo original precisa ter 50 atividades problemáticas');

        $notification = new ProntidaoSemanalNotification($resumo);

        $this->assertCount(5, $notification->snapshotParaTeste()->destaques, 'O snapshot da Notification nunca deve exceder MAX_DESTAQUES');

        // Reflection direta na propriedade "snapshot" (a única de domínio
        // declarada pela Notification — as demais propriedades vêm da
        // trait Queueable: connection/queue/delay/etc., infraestrutura de
        // fila, nunca dado de domínio).
        $reflection = new ReflectionClass($notification);
        $propriedade = $reflection->getProperty('snapshot');
        $propriedade->setAccessible(true);
        $valor = $propriedade->getValue($notification);

        $this->assertInstanceOf(SnapshotDigestProntidao::class, $valor);
        $this->assertNotInstanceOf(ResumoDigestProntidao::class, $valor);

        $tipo = $propriedade->getType();
        $this->assertInstanceOf(ReflectionNamedType::class, $tipo);
        $this->assertSame(SnapshotDigestProntidao::class, $tipo->getName());

        // Defesa adicional: percorre TODAS as propriedades da instância
        // (inclusive as herdadas da trait Queueable) e confirma que
        // NENHUMA delas é um ResumoDigestProntidao ou um array com mais
        // de MAX_DESTAQUES elementos — nunca a árvore completa escondida
        // em outro lugar.
        foreach ($reflection->getProperties() as $p) {
            $p->setAccessible(true);
            if (! $p->isInitialized($notification)) {
                continue;
            }
            $v = $p->getValue($notification);
            $this->assertNotInstanceOf(ResumoDigestProntidao::class, $v, "Propriedade '{$p->getName()}' não pode conter o ResumoDigestProntidao completo");
            if (is_array($v)) {
                $this->assertLessThanOrEqual(SnapshotDigestProntidao::MAX_DESTAQUES, count($v), "Propriedade '{$p->getName()}' não pode carregar um array maior que MAX_DESTAQUES");
            }
        }
    }

    public function test_tamanho_serializado_da_notification_e_drasticamente_menor_que_o_resumo_original(): void
    {
        $resumo = $this->resumoComNAtividadesProblematicas(50);
        $notification = new ProntidaoSemanalNotification($resumo);

        $tamanhoResumoCompleto = strlen(serialize($resumo));
        $tamanhoNotification = strlen(serialize($notification));

        $this->assertLessThan(
            $tamanhoResumoCompleto * 0.2,
            $tamanhoNotification,
            'A Notification deve ser uma fração pequena do resumo completo — prova de que a árvore de AtividadeProntidaoView não viaja pra fila'
        );
    }

    public function test_tamanho_da_notification_nao_cresce_linearmente_com_o_numero_de_atividades(): void
    {
        $resumoPequeno = $this->resumoComNAtividadesProblematicas(3);
        $notificationPequena = new ProntidaoSemanalNotification($resumoPequeno);
        $tamanhoPequeno = strlen(serialize($notificationPequena));

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id, 'name' => 'Obra Teste Digest 2']);

        $resumoGrande = $this->resumoComNAtividadesProblematicas(100);
        $notificationGrande = new ProntidaoSemanalNotification($resumoGrande);
        $tamanhoGrande = strlen(serialize($notificationGrande));

        // 3 atividades -> 3 destaques; 100 atividades -> tampado em 5
        // destaques (MAX_DESTAQUES). O tamanho não pode crescer na
        // proporção 100/3 (~33x) — deve ficar limitado pela diferença
        // entre 3 e 5 destaques, não pelo total de atividades.
        $this->assertLessThan($tamanhoPequeno * 3, $tamanhoGrande, 'O payload não pode crescer proporcionalmente ao número de atividades problemáticas');
    }

    // =========================================================================
    // 3) Contagens
    // =========================================================================

    public function test_contagens_batem_com_o_resumo_original(): void
    {
        $resumo = $this->resumoComNAtividadesProblematicas(4); // 2 NaoPronta + 2 Atencao
        $notification = new ProntidaoSemanalNotification($resumo);
        $snapshot = $notification->snapshotParaTeste();

        $this->assertSame($resumo->totalAtividadesUniverso, $snapshot->totalAtividadesUniverso);
        $this->assertSame($resumo->totalNaoPronta, $snapshot->totalNaoPronta);
        $this->assertSame($resumo->totalAtencao, $snapshot->totalAtencao);
        $this->assertSame($resumo->totalExigeAtencao, $snapshot->totalExigeAtencao);
        $this->assertSame($resumo->horizonteDias, $snapshot->horizonteDias);

        $array = $notification->toArray($this->user);
        $this->assertSame($resumo->totalExigeAtencao, $array['total_exige_atencao']);
        $this->assertSame($resumo->totalNaoPronta, $array['total_nao_pronta']);
        $this->assertSame($resumo->totalAtencao, $array['total_atencao']);
        $this->assertSame($resumo->horizonteDias, $array['horizonte_dias']);
    }

    // =========================================================================
    // 4) Obra
    // =========================================================================

    public function test_obra_correta_no_snapshot_e_no_array(): void
    {
        $resumo = $this->resumoComNAtividadesProblematicas(1);
        $notification = new ProntidaoSemanalNotification($resumo);

        $this->assertSame($this->obra->id, $notification->snapshotParaTeste()->obraId);
        $this->assertSame('Obra Teste Digest', $notification->snapshotParaTeste()->obraNome);

        $array = $notification->toArray($this->user);
        $this->assertSame($this->obra->id, $array['obra_id']);
        $this->assertStringContainsString('Obra Teste Digest', $array['titulo']);
    }

    // =========================================================================
    // 5) Link
    // =========================================================================

    public function test_link_usa_radar_entrar_nao_central_prontidao_direto(): void
    {
        $resumo = $this->resumoComNAtividadesProblematicas(1);
        $notification = new ProntidaoSemanalNotification($resumo);

        $array = $notification->toArray($this->user);
        $linkEsperado = route('radar.entrar', ['obraId' => $this->obra->id]);

        $this->assertSame($linkEsperado, $array['link']);
        $this->assertStringNotContainsString('central-prontidao', $array['link']);
    }

    // =========================================================================
    // 6) Serialização real
    // =========================================================================

    public function test_serialize_unserialize_preserva_conteudo_necessario(): void
    {
        $resumo = $this->resumoComNAtividadesProblematicas(7);
        $notification = new ProntidaoSemanalNotification($resumo);

        $serializado = serialize($notification);
        /** @var ProntidaoSemanalNotification $reidratada */
        $reidratada = unserialize($serializado);

        $this->assertSame($notification->snapshotParaTeste()->obraId, $reidratada->snapshotParaTeste()->obraId);
        $this->assertSame($notification->snapshotParaTeste()->totalExigeAtencao, $reidratada->snapshotParaTeste()->totalExigeAtencao);
        $this->assertCount(5, $reidratada->snapshotParaTeste()->destaques);
        $this->assertSame(
            $notification->toArray($this->user)['mensagem'],
            $reidratada->toArray($this->user)['mensagem']
        );
        $this->assertSame(['database', 'broadcast'], $reidratada->via($this->user));
    }

    // =========================================================================
    // 7) Broadcast equivalente ao database
    // =========================================================================

    public function test_broadcast_e_semanticamente_igual_ao_database(): void
    {
        $resumo = $this->resumoComNAtividadesProblematicas(2);
        $notification = new ProntidaoSemanalNotification($resumo);

        $this->assertSame($notification->toArray($this->user), $notification->toBroadcast($this->user));
    }

    // =========================================================================
    // 8) Sem banco durante a renderização
    // =========================================================================

    public function test_toarray_e_tobroadcast_nao_executam_query(): void
    {
        $resumo = $this->resumoComNAtividadesProblematicas(3);
        $notification = new ProntidaoSemanalNotification($resumo);

        $queryCount = 0;
        DB::listen(function () use (&$queryCount) {
            $queryCount++;
        });

        $notification->toArray($this->user);
        $notification->toBroadcast($this->user);
        $notification->via($this->user);

        $this->assertSame(0, $queryCount, 'Depois de construída, a Notification não deve tocar o banco pra renderizar a mensagem');
    }

    // =========================================================================
    // 9) Destaques
    // =========================================================================

    public function test_destaques_respeitam_maximo_de_cinco(): void
    {
        $resumo = $this->resumoComNAtividadesProblematicas(12);
        $notification = new ProntidaoSemanalNotification($resumo);

        $this->assertLessThanOrEqual(5, count($notification->snapshotParaTeste()->destaques));
        $this->assertCount(5, $notification->snapshotParaTeste()->destaques);
    }

    public function test_destaques_ordenam_nao_pronta_antes_de_atencao(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $atencaoCedo = $this->criarAtividade(['external_uid' => 'a1', 'inicio_planejado' => now()->addDays(1)]);
        $this->tornarAtencao($atencaoCedo);

        $naoProntaTarde = $this->criarAtividade(['external_uid' => 'a2', 'inicio_planejado' => now()->addDays(20)]);
        $this->tornarNaoPronta($naoProntaTarde);

        $resumo = $this->service->consolidar($this->obra);
        $notification = new ProntidaoSemanalNotification($resumo);
        $destaques = $notification->snapshotParaTeste()->destaques;

        $this->assertSame(StatusOperacionalProntidao::NaoPronta, $destaques[0]->statusOperacional, 'Não Pronta deve vir antes mesmo com início planejado mais distante');
        $this->assertSame(StatusOperacionalProntidao::Atencao, $destaques[1]->statusOperacional);

        Carbon::setTestNow();
    }

    public function test_destaques_ordenam_por_inicio_planejado_mais_proximo_dentro_do_mesmo_status(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-01-01 00:00:00'));

        $longe = $this->criarAtividade(['external_uid' => 'b1', 'inicio_planejado' => now()->addDays(15)]);
        $this->tornarNaoPronta($longe);

        $perto = $this->criarAtividade(['external_uid' => 'b2', 'inicio_planejado' => now()->addDays(2)]);
        $this->tornarNaoPronta($perto);

        $resumo = $this->service->consolidar($this->obra);
        $notification = new ProntidaoSemanalNotification($resumo);
        $destaques = $notification->snapshotParaTeste()->destaques;

        $this->assertCount(2, $destaques);
        $this->assertTrue($destaques[0]->inicioPlanejado->equalTo(Carbon::parse('2026-01-03 00:00:00')), 'A atividade com início mais próximo (perto) deve vir primeiro');
        $this->assertTrue($destaques[1]->inicioPlanejado->equalTo(Carbon::parse('2026-01-16 00:00:00')), 'A atividade com início mais distante (longe) deve vir por último');

        Carbon::setTestNow();
    }

    public function test_destaque_carrega_somente_campos_simples(): void
    {
        $resumo = $this->resumoComNAtividadesProblematicas(1);
        $notification = new ProntidaoSemanalNotification($resumo);
        $destaque = $notification->snapshotParaTeste()->destaques[0];

        $reflection = new ReflectionClass($destaque);
        $nomesPropriedades = array_map(fn ($p) => $p->getName(), $reflection->getProperties());

        $this->assertEqualsCanonicalizing(
            ['codigo', 'nome', 'statusOperacional', 'inicioPlanejado', 'resumoMotivos'],
            $nomesPropriedades,
            'DestaqueProntidao não pode carregar mais campos além dos mínimos definidos'
        );
    }

    public function test_sem_atividades_problematicas_gera_snapshot_sem_destaques(): void
    {
        $this->criarAtividade(['inicio_planejado' => now()->addDays(3)]); // pronta

        $resumo = $this->service->consolidar($this->obra);
        $notification = new ProntidaoSemanalNotification($resumo);

        $this->assertSame([], $notification->snapshotParaTeste()->destaques);
        $this->assertSame(0, $notification->toArray($this->user)['total_exige_atencao']);
        $this->assertStringNotContainsString('Próximas:', $notification->toArray($this->user)['mensagem']);
    }
}
