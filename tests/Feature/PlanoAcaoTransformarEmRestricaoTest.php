<?php

namespace Tests\Feature;

use App\Enums\StatusPlanoAcao;
use App\Enums\StatusRestricao;
use App\Models\Atividade;
use App\Models\CronogramaImportacao;
use App\Models\ItemSuprimento;
use App\Models\Perfil;
use App\Models\PerfilPermissao;
use App\Models\PlanoAcao;
use App\Models\Restricao;
use App\Models\RestricaoAcao;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Work;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ciclo 11, Etapa B — ponte "PlanoAcao → decisão humana explícita →
 * Restrição por atividade" (App\Models\PlanoAcao::transformarEmRestricoes(),
 * método Livewire homônimo em ⚡plano-acao.blade.php). Transformação
 * explícita e manual, idempotente por (tenant_id, origem_plano_acao_id,
 * atividade_id), SEM qualquer sincronização de ciclo de vida depois de
 * criada.
 */
class PlanoAcaoTransformarEmRestricaoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;
    private User $user;
    private Work $obra;
    private CronogramaImportacao $importacao;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->user = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->actingAs($this->user);
        $this->obra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        // 'engenheiro' tem 'editar' em restricoes.plano_acao (nível 3) e
        // 'criar' em restricoes.quadro (nível 2, exigido pra Restricao) —
        // satisfaz as DUAS autorizações exigidas pela operação.
        $this->vincularObra($this->obra, $this->user, 'engenheiro');

        $this->importacao = CronogramaImportacao::create([
            'obra_id' => $this->obra->id,
            'importado_em' => now(),
        ]);
    }

    private function criarAcao(array $overrides = []): PlanoAcao
    {
        return PlanoAcao::create(array_merge([
            'obra_id' => $this->obra->id,
            'cronograma_importacao_origem_id' => $this->importacao->id,
            'regra_id' => 'STRUCT-005',
            'titulo' => 'Ciclo lógico identificado no cronograma',
            'recomendacao' => 'Revise as relações de predecessora/sucessora.',
            'status' => StatusPlanoAcao::Aberta,
            'uids_referencia' => [],
        ], $overrides));
    }

    private function criarAtividade(array $overrides = []): Atividade
    {
        return Atividade::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'fora_do_cronograma' => false,
        ], $overrides));
    }

    /** Perfil sob medida: só as permissões passadas em $permissoes, nada mais. */
    private function perfilComPermissoes(array $permissoes): Perfil
    {
        $perfil = Perfil::create([
            'tenant_id' => $this->tenant->id,
            'nome' => 'Perfil de Teste Ciclo 11',
        ]);

        foreach ($permissoes as [$funcionalidade, $acao]) {
            PerfilPermissao::create([
                'tenant_id' => $this->tenant->id,
                'perfil_id' => $perfil->id,
                'funcionalidade' => $funcionalidade,
                'acao' => $acao,
            ]);
        }

        return $perfil;
    }

    // =========================================================================
    // 1/2 — CRIAÇÃO INDIVIDUAL E EM LOTE
    // =========================================================================

    public function test_cria_restricao_para_atividade_elegivel(): void
    {
        $at = $this->criarAtividade(['external_uid' => '10']);
        $acao = $this->criarAcao(['uids_referencia' => ['10']]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('transformarEmRestricoes', $acao->id, [$at->id]);

        $this->assertSame(1, Restricao::where('origem_plano_acao_id', $acao->id)->count());
        $this->assertDatabaseHas('restricoes', [
            'origem_plano_acao_id' => $acao->id,
            'atividade_id' => $at->id,
        ]);
    }

    public function test_cria_varias_restricoes_em_lote(): void
    {
        $at1 = $this->criarAtividade(['external_uid' => '10']);
        $at2 = $this->criarAtividade(['external_uid' => '11']);
        $at3 = $this->criarAtividade(['external_uid' => '12']);
        $acao = $this->criarAcao(['uids_referencia' => ['10', '11', '12']]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('transformarEmRestricoes', $acao->id, [$at1->id, $at2->id, $at3->id]);

        $this->assertSame(3, Restricao::where('origem_plano_acao_id', $acao->id)->count());
    }

    // =========================================================================
    // 3 — fora_do_cronograma
    // =========================================================================

    public function test_atividade_fora_do_cronograma_nao_gera_restricao(): void
    {
        $ativa = $this->criarAtividade(['external_uid' => '10', 'fora_do_cronograma' => false]);
        $arquivada = $this->criarAtividade(['external_uid' => '11', 'fora_do_cronograma' => true]);
        $acao = $this->criarAcao(['uids_referencia' => ['10', '11']]);

        $component = Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('transformarEmRestricoes', $acao->id, [$ativa->id, $arquivada->id]);

        $this->assertSame(1, Restricao::where('origem_plano_acao_id', $acao->id)->count());
        $this->assertDatabaseHas('restricoes', ['origem_plano_acao_id' => $acao->id, 'atividade_id' => $ativa->id]);
        $this->assertDatabaseMissing('restricoes', ['origem_plano_acao_id' => $acao->id, 'atividade_id' => $arquivada->id]);

        // Retorno do domínio contabiliza a atividade ignorada por este motivo.
        $resultado = $acao->transformarEmRestricoes([$arquivada->id]);
        $this->assertSame(0, $resultado['criadas']);
        $this->assertSame(1, $resultado['ignoradasForaDoCronograma']);
    }

    // =========================================================================
    // 4 — UID sem Atividade correspondente
    // =========================================================================

    public function test_uid_sem_atividade_correspondente_nao_gera_erro(): void
    {
        // 'uid-fantasma' está em uids_referencia mas nenhuma Atividade tem esse external_uid.
        $acao = $this->criarAcao(['uids_referencia' => ['uid-fantasma']]);

        // Seleção com um ID que não corresponde a nenhuma Atividade válida
        // desta obra/deste PlanoAcao — deve ser ignorado silenciosamente,
        // nunca lançar exceção.
        $component = Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('transformarEmRestricoes', $acao->id, ['id-que-nao-existe']);

        $component->assertOk();
        $this->assertSame(0, Restricao::where('origem_plano_acao_id', $acao->id)->count());
    }

    // =========================================================================
    // 5 — Duplicidade (mesma origem + atividade)
    // =========================================================================

    public function test_atividade_ja_vinculada_nao_duplica(): void
    {
        $at = $this->criarAtividade(['external_uid' => '10']);
        $acao = $this->criarAcao(['uids_referencia' => ['10']]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('transformarEmRestricoes', $acao->id, [$at->id]);
        $this->assertSame(1, Restricao::where('origem_plano_acao_id', $acao->id)->count());

        // Segunda tentativa, mesma atividade, mesmo PlanoAcao.
        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('transformarEmRestricoes', $acao->id, [$at->id]);

        $this->assertSame(1, Restricao::where('origem_plano_acao_id', $acao->id)->count());
    }

    // =========================================================================
    // 6 — Proteção de banco (índice UNIQUE)
    // =========================================================================

    public function test_indice_unique_impede_duplicacao_no_banco(): void
    {
        $at = $this->criarAtividade(['external_uid' => '10']);
        $acao = $this->criarAcao(['uids_referencia' => ['10']]);

        Restricao::create([
            'atividade_id' => $at->id,
            'origem_plano_acao_id' => $acao->id,
            'descricao' => 'Primeira',
            'bloqueante' => false,
            'categoria_id' => null,
            'status' => StatusRestricao::Aberta->value,
            'aberta_em' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Insert cru, contornando de propósito a checagem em memória do
        // domínio — prova que quem realmente impede a duplicidade é o
        // índice UNIQUE do banco, não só a lógica em PHP.
        Restricao::create([
            'atividade_id' => $at->id,
            'origem_plano_acao_id' => $acao->id,
            'descricao' => 'Segunda (deveria colidir)',
            'bloqueante' => false,
            'categoria_id' => null,
            'status' => StatusRestricao::Aberta->value,
            'aberta_em' => now(),
        ]);
    }

    public function test_transformar_e_idempotente_mesmo_chamado_duas_vezes_seguidas_no_dominio(): void
    {
        // Não é um teste de concorrência real (2 requisições simultâneas
        // de verdade não são simuláveis num teste PHP single-threaded) —
        // é a via prática que a checagem em memória do domínio cobre: 2
        // chamadas sequenciais nunca resultam em 2 Restrições. O catch de
        // QueryException/1062 em transformarEmRestricoes() é defesa em
        // profundidade pro caso de corrida real, coberto pelo teste
        // anterior a nível de banco (índice UNIQUE), não exercitado
        // diretamente aqui.
        $at = $this->criarAtividade(['external_uid' => '10']);
        $acao = $this->criarAcao(['uids_referencia' => ['10']]);

        $r1 = $acao->transformarEmRestricoes([$at->id]);
        $r2 = $acao->transformarEmRestricoes([$at->id]);

        $this->assertSame(1, $r1['criadas']);
        $this->assertSame(0, $r2['criadas']);
        $this->assertSame(1, $r2['ignoradasJaVinculadas']);
        $this->assertSame(1, Restricao::where('origem_plano_acao_id', $acao->id)->count());
    }

    // =========================================================================
    // 7/8/9 — PERMISSÕES
    // =========================================================================

    public function test_usuario_sem_permissao_de_planoacao_nao_consegue(): void
    {
        $at = $this->criarAtividade(['external_uid' => '10']);
        $acao = $this->criarAcao(['uids_referencia' => ['10']]);

        // 'encarregado': tem restricoes.quadro/criar (nível 2) mas NÃO tem
        // restricoes.plano_acao/editar (exige nível 3, Engenheiro).
        $semPermissaoPlanoAcao = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->vincularObra($this->obra, $semPermissaoPlanoAcao, 'encarregado');
        $this->actingAs($semPermissaoPlanoAcao);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('transformarEmRestricoes', $acao->id, [$at->id])
            ->assertForbidden();

        $this->assertSame(0, Restricao::where('origem_plano_acao_id', $acao->id)->count());
    }

    public function test_usuario_sem_permissao_de_restricao_nao_consegue(): void
    {
        $at = $this->criarAtividade(['external_uid' => '10']);
        $acao = $this->criarAcao(['uids_referencia' => ['10']]);

        // Perfil sob medida: só restricoes.plano_acao (ver+editar), SEM
        // nenhuma permissão em restricoes.quadro — isola especificamente a
        // falha da segunda autorização (RestricaoPolicy::create).
        $perfil = $this->perfilComPermissoes([
            ['restricoes.plano_acao', 'ver'],
            ['restricoes.plano_acao', 'editar'],
        ]);
        $semPermissaoRestricao = User::factory()->create(['tenant_id' => $this->tenant->id]);
        $this->obra->users()->attach($semPermissaoRestricao->id, ['perfil_id' => $perfil->id]);
        $this->actingAs($semPermissaoRestricao);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('transformarEmRestricoes', $acao->id, [$at->id])
            ->assertForbidden();

        $this->assertSame(0, Restricao::where('origem_plano_acao_id', $acao->id)->count());
    }

    public function test_usuario_de_outro_tenant_nao_consegue(): void
    {
        $at = $this->criarAtividade(['external_uid' => '10']);
        $acao = $this->criarAcao(['uids_referencia' => ['10']]);

        $outroTenant = Tenant::factory()->create();
        $usuarioOutroTenant = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->actingAs($usuarioOutroTenant);

        // Sem vínculo com a obra: a própria página já bloqueia no mount().
        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->assertRedirect()
            ->assertSessionHas('flash.popup', 'acesso-negado');

        $this->assertSame(0, Restricao::where('origem_plano_acao_id', $acao->id)->count());
    }

    // =========================================================================
    // 10 — SELEÇÃO VAZIA
    // =========================================================================

    public function test_selecao_vazia_nao_cria_nada(): void
    {
        $acao = $this->criarAcao(['uids_referencia' => ['10']]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('transformarEmRestricoes', $acao->id, [])
            ->assertOk();

        $this->assertSame(0, Restricao::where('origem_plano_acao_id', $acao->id)->count());
    }

    // =========================================================================
    // 11 — SEM RestricaoAcao
    // =========================================================================

    public function test_nenhuma_restricaoacao_e_criada(): void
    {
        $at = $this->criarAtividade(['external_uid' => '10']);
        $acao = $this->criarAcao(['uids_referencia' => ['10']]);
        $totalAntes = RestricaoAcao::count();

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('transformarEmRestricoes', $acao->id, [$at->id]);

        $this->assertSame($totalAntes, RestricaoAcao::count());
    }

    // =========================================================================
    // 12 — PlanoAcao permanece intacto
    // =========================================================================

    public function test_planoacao_permanece_intacto(): void
    {
        $at = $this->criarAtividade(['external_uid' => '10']);
        $acao = $this->criarAcao(['uids_referencia' => ['10'], 'status' => StatusPlanoAcao::Aberta]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('transformarEmRestricoes', $acao->id, [$at->id]);

        $fresca = $acao->fresh();
        $this->assertSame(StatusPlanoAcao::Aberta, $fresca->status);
        $this->assertSame(['10'], $fresca->uids_referencia);
        $this->assertNull($fresca->resolvida_em);
    }

    // =========================================================================
    // 13 — Campos corretos da Restrição criada
    // =========================================================================

    public function test_restricao_criada_tem_campos_corretos(): void
    {
        $at = $this->criarAtividade(['external_uid' => '10']);
        $acao = $this->criarAcao(['uids_referencia' => ['10']]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('transformarEmRestricoes', $acao->id, [$at->id]);

        $restricao = Restricao::where('origem_plano_acao_id', $acao->id)->first();

        $this->assertSame($acao->id, $restricao->origem_plano_acao_id);
        $this->assertSame($at->id, $restricao->atividade_id);
        $this->assertSame(StatusRestricao::Aberta, $restricao->status);
        $this->assertNotNull($restricao->aberta_em);
        $this->assertFalse($restricao->bloqueante);
        $this->assertNull($restricao->categoria_id);
    }

    // =========================================================================
    // 14 — ISOLAMENTO TENANT/OBRA
    // =========================================================================

    public function test_atividade_de_outra_obra_nao_e_selecionavel(): void
    {
        $outraObra = Work::factory()->create(['tenant_id' => $this->tenant->id]);
        $atDeOutraObra = $this->criarAtividade(['obra_id' => $outraObra->id, 'external_uid' => '10']);
        // Mesmo external_uid '10' referenciado, mas a atividade pertence a
        // outra obra — transformarEmRestricoes() filtra por obra_id do
        // PRÓPRIO PlanoAcao, nunca cruza pra outra obra.
        $acao = $this->criarAcao(['uids_referencia' => ['10']]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('transformarEmRestricoes', $acao->id, [$atDeOutraObra->id]);

        $this->assertSame(0, Restricao::where('origem_plano_acao_id', $acao->id)->count());
    }

    public function test_isolamento_de_tenant_na_criacao(): void
    {
        $at = $this->criarAtividade(['external_uid' => '10']);
        $acao = $this->criarAcao(['uids_referencia' => ['10']]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('transformarEmRestricoes', $acao->id, [$at->id]);

        $restricao = Restricao::where('origem_plano_acao_id', $acao->id)->first();
        $this->assertSame($this->tenant->id, $restricao->tenant_id);

        $outroTenant = Tenant::factory()->create();
        $usuarioOutroTenant = User::factory()->create(['tenant_id' => $outroTenant->id]);
        $this->actingAs($usuarioOutroTenant);

        $this->assertSame(0, Restricao::where('id', $restricao->id)->count());
    }

    // =========================================================================
    // 15 — COEXISTÊNCIA COM OUTRA ORIGEM NA MESMA ATIVIDADE
    // =========================================================================

    public function test_restricao_de_outra_origem_na_mesma_atividade_continua_permitida(): void
    {
        $at = $this->criarAtividade(['external_uid' => '10']);
        $acao = $this->criarAcao(['uids_referencia' => ['10']]);

        // Restrição manual pré-existente na mesma atividade (sem nenhuma origem automática).
        $manual = Restricao::create([
            'atividade_id' => $at->id,
            'descricao' => 'Restrição manual pré-existente',
            'bloqueante' => true,
            'status' => StatusRestricao::Aberta->value,
            'aberta_em' => now(),
        ]);

        // Restrição de origem Suprimento na mesma atividade.
        $item = ItemSuprimento::create([
            'tenant_id' => $this->tenant->id,
            'obra_id' => $this->obra->id,
            'nome' => 'Item de Suprimento de Teste',
        ]);
        $deSuprimento = Restricao::create([
            'atividade_id' => $at->id,
            'origem_suprimento_item_id' => $item->id,
            'descricao' => 'Suprimentos: item em risco.',
            'bloqueante' => true,
            'status' => StatusRestricao::Aberta->value,
            'aberta_em' => now(),
        ]);

        Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('transformarEmRestricoes', $acao->id, [$at->id]);

        // As 3 coexistem — nenhuma foi tocada/duplicada/removida pelas outras.
        $this->assertSame(3, Restricao::where('atividade_id', $at->id)->count());
        $this->assertNotNull($manual->fresh());
        $this->assertNotNull($deSuprimento->fresh());
        $this->assertSame(1, Restricao::where('origem_plano_acao_id', $acao->id)->where('atividade_id', $at->id)->count());
    }

    // =========================================================================
    // UX — mensagem de toast com contagens
    // =========================================================================

    public function test_mensagem_de_toast_informa_criadas_e_ignoradas(): void
    {
        $ativa = $this->criarAtividade(['external_uid' => '10']);
        $arquivada = $this->criarAtividade(['external_uid' => '11', 'fora_do_cronograma' => true]);
        $jaVinculada = $this->criarAtividade(['external_uid' => '12']);
        $acao = $this->criarAcao(['uids_referencia' => ['10', '11', '12']]);

        Restricao::create([
            'atividade_id' => $jaVinculada->id,
            'origem_plano_acao_id' => $acao->id,
            'descricao' => 'Já vinculada antes',
            'bloqueante' => false,
            'status' => StatusRestricao::Aberta->value,
            'aberta_em' => now(),
        ]);

        $component = Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
            ->call('transformarEmRestricoes', $acao->id, [$ativa->id, $arquivada->id, $jaVinculada->id]);

        $component->assertDispatched('show-toast', function (string $name, array $params) {
            return str_contains($params['message'], '1 restrição criada.')
                && str_contains($params['message'], '1 atividade fora do cronograma')
                && str_contains($params['message'], '1 já vinculada');
        });
    }

    // =========================================================================
    // Ciclo 11, Etapa B.3 — fechamento das lacunas da auditoria B.2
    // =========================================================================

    /**
     * Atividade real, da MESMA obra, com ID genuíno — mas seu external_uid
     * nunca esteve em uids_referencia DESTE PlanoAcao (pertence, na prática,
     * a um problema diferente). É o caso de forjadura mais realista: um
     * usuário mal-intencionado (ou um bug de UI) reaproveitando o ID de uma
     * atividade de OUTRO Plano de Ação da mesma obra.
     */
    public function test_atividade_real_da_obra_mas_fora_deste_planoacao_e_ignorada(): void
    {
        $atDeOutroProblema = $this->criarAtividade(['external_uid' => '99']);
        // uids_referencia deste PlanoAcao nunca inclui '99'.
        $acao = $this->criarAcao(['uids_referencia' => ['10']]);

        $resultado = $acao->transformarEmRestricoes([$atDeOutroProblema->id]);

        $this->assertSame(0, $resultado['criadas']);
        $this->assertSame(1, $resultado['ignoradasInvalidas']);
        $this->assertSame(0, $resultado['ignoradasForaDoCronograma']);
        $this->assertSame(0, $resultado['ignoradasJaVinculadas']);
        $this->assertSame(0, Restricao::where('origem_plano_acao_id', $acao->id)->count());
    }

    /**
     * Testa o comportamento JÁ EXISTENTE de array_unique() em
     * transformarEmRestricoes() — não altera a implementação, só prova que
     * enviar a mesma atividade repetida na seleção nunca duplica.
     */
    public function test_ids_duplicados_na_selecao_criam_apenas_uma_restricao(): void
    {
        $at = $this->criarAtividade(['external_uid' => '10']);
        $acao = $this->criarAcao(['uids_referencia' => ['10']]);

        $resultado = $acao->transformarEmRestricoes([$at->id, $at->id, $at->id]);

        $this->assertSame(1, $resultado['criadas']);
        $this->assertSame(0, $resultado['ignoradasInvalidas']);
        $this->assertSame(0, $resultado['ignoradasForaDoCronograma']);
        $this->assertSame(0, $resultado['ignoradasJaVinculadas']);
        $this->assertSame(1, Restricao::where('origem_plano_acao_id', $acao->id)->count());
    }

    /**
     * Verifica campos reais da Atividade após a transformação (não só
     * "não encontrei update() no código") — inclusive `updated_at`, que
     * mudaria se QUALQUER save() tivesse acontecido na Atividade.
     */
    public function test_atividade_permanece_intacta_apos_transformacao(): void
    {
        $at = $this->criarAtividade([
            'external_uid' => '10',
            'nome' => 'Atividade Original Intacta',
            'fora_do_cronograma' => false,
        ]);
        $acao = $this->criarAcao(['uids_referencia' => ['10']]);

        $nomeAntes = $at->nome;
        $foraDoCronogramaAntes = $at->fora_do_cronograma;
        $updatedAtAntes = $at->updated_at;

        $acao->transformarEmRestricoes([$at->id]);

        $fresca = $at->fresh();
        $this->assertSame($nomeAntes, $fresca->nome);
        $this->assertSame($foraDoCronogramaAntes, $fresca->fora_do_cronograma);
        $this->assertTrue($updatedAtAntes->equalTo($fresca->updated_at), 'updated_at da Atividade mudou — algo salvou nela.');
    }

    /**
     * Exercita de verdade o catch de QueryException/1062 dentro do
     * `foreach` — NUNCA pré-cria todas as Restrições antes da chamada
     * (isso só testaria a checagem em memória). Em vez disso, usa um
     * listener `Restricao::creating` (dispara ANTES do INSERT real da
     * 1ª atividade do lote, portanto ainda DENTRO da mesma chamada a
     * transformarEmRestricoes()) para inserir, por fora do loop, a
     * combinação (origem_plano_acao_id, atividade_id) que o loop só vai
     * tentar criar DEPOIS, pra $at2 — simula fielmente uma requisição
     * concorrente que "ganhou a corrida" entre a checagem em memória e o
     * INSERT real de $at2. Quando o loop chega em $at2, o INSERT bate
     * numa violação 1062 genuína (gerada pelo MySQL de verdade, não
     * mockada), capturada pelo catch do método.
     */
    public function test_violacao_1062_no_meio_do_lote_nao_aborta_as_demais_atividades(): void
    {
        $at1 = $this->criarAtividade(['external_uid' => '10']);
        $at2 = $this->criarAtividade(['external_uid' => '11']);
        $at3 = $this->criarAtividade(['external_uid' => '12']);
        $acao = $this->criarAcao(['uids_referencia' => ['10', '11', '12']]);

        $jaExecutado = false;
        Restricao::creating(function () use (&$jaExecutado, $acao, $at2) {
            if ($jaExecutado) {
                return;
            }
            $jaExecutado = true;

            // Sem withoutEvents(): o próprio guard acima ($jaExecutado)
            // já impede recursão infinita quando este create() disparar
            // "creating" de novo — desligar os eventos aqui removeria
            // também o listener do BelongsToTenant que carimba tenant_id.
            Restricao::create([
                'atividade_id' => $at2->id,
                'origem_plano_acao_id' => $acao->id,
                'descricao' => 'Inserida "por fora", simulando uma requisição concorrente real',
                'bloqueante' => false,
                'categoria_id' => null,
                'status' => StatusRestricao::Aberta->value,
                'aberta_em' => now(),
            ]);
        });

        try {
            $resultado = $acao->transformarEmRestricoes([$at1->id, $at2->id, $at3->id]);
        } finally {
            Restricao::flushEventListeners();
        }

        // at1 e at3 criadas normalmente pelo próprio loop; at2 colidiu no
        // INSERT real (1062) e foi contabilizada como já vinculada — o
        // lote inteiro NUNCA foi abortado por causa dela.
        $this->assertSame(2, $resultado['criadas']);
        $this->assertSame(1, $resultado['ignoradasJaVinculadas']);
        $this->assertSame(0, $resultado['ignoradasInvalidas']);
        $this->assertSame(0, $resultado['ignoradasForaDoCronograma']);

        // As 3 atividades têm, cada uma, exatamente 1 Restricao — a de
        // $at2 é a que foi inserida "por fora", não uma criada pelo loop.
        $this->assertSame(3, Restricao::where('origem_plano_acao_id', $acao->id)->count());
        $this->assertDatabaseHas('restricoes', ['origem_plano_acao_id' => $acao->id, 'atividade_id' => $at1->id]);
        $this->assertDatabaseHas('restricoes', ['origem_plano_acao_id' => $acao->id, 'atividade_id' => $at2->id]);
        $this->assertDatabaseHas('restricoes', ['origem_plano_acao_id' => $acao->id, 'atividade_id' => $at3->id]);
    }

    /**
     * Prova que um erro de banco GENUÍNO (não mockado) diferente de 1062
     * nunca é engolido — e que a transação inteira (aberta por
     * transacaoSegura() no Livewire, nunca dentro do próprio método de
     * domínio) desfaz TUDO, inclusive Restrições já criadas mais cedo no
     * MESMO lote. Provoca o erro explorando a FK real de
     * `restricoes.atividade_id` (`cascadeOnDelete`, mas NUNCA nullable):
     * remove a linha de $at2 em `atividades` via DB::table() (delete cru,
     * sem passar pelos eventos do Eloquent) bem antes do loop chegar nela
     * — o INSERT da Restricao de $at2 então viola a FK de verdade
     * (MySQL 1452), nunca 1062.
     */
    public function test_erro_de_banco_diferente_de_1062_provoca_rollback_completo_do_lote(): void
    {
        $at1 = $this->criarAtividade(['external_uid' => '10']);
        $at2 = $this->criarAtividade(['external_uid' => '11']);
        $at3 = $this->criarAtividade(['external_uid' => '12']);
        $acao = $this->criarAcao(['uids_referencia' => ['10', '11', '12']]);

        $jaExecutado = false;
        Restricao::creating(function () use (&$jaExecutado, $at2) {
            if ($jaExecutado) {
                return;
            }
            $jaExecutado = true;

            DB::table('atividades')->where('id', $at2->id)->delete();
        });

        try {
            Livewire::test('pages::radar.plano-acao', ['obra' => $this->obra])
                ->call('transformarEmRestricoes', $acao->id, [$at1->id, $at2->id, $at3->id]);
        } finally {
            Restricao::flushEventListeners();
        }

        // Rollback completo: nem a Restricao de $at1 (criada com sucesso
        // ANTES da falha, mas na mesma transação) sobrevive — o
        // DB::transaction() de transacaoSegura() desfez tudo. $at3 nunca
        // chegou a ser tentada (o foreach para na exceção não-1062 de $at2).
        $this->assertSame(0, Restricao::where('origem_plano_acao_id', $acao->id)->count());
    }
}
