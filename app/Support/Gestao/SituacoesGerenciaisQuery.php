<?php

namespace App\Support\Gestao;

use App\Enums\EstadoCoberturaMaterial;
use App\Enums\SeveridadeSituacao;
use App\Enums\SituacaoEntregaPedido;
use App\Enums\StatusAtividade;
use App\Enums\StatusInventarioEstoque;
use App\Enums\StatusOrdemIndustrializacao;
use App\Enums\StatusPedidoCompra;
use App\Enums\StatusReservaEstoque;
use App\Enums\TipoSituacaoGerencial;
use App\DTOs\Gestao\SituacaoGerencial;
use App\Models\Atividade;
use App\Models\DocumentoEngenharia;
use App\Models\InventarioEstoque;
use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\MovimentacaoEstoque;
use App\Models\OrdemIndustrializacao;
use App\Models\PedidoCompra;
use App\Models\ReservaEstoque;
use App\Models\User;
use App\Models\Work;
use App\Support\Estoque\CoberturaReservas;
use App\Support\Estoque\ConciliacaoAplicacao;
use App\Support\Estoque\ConciliacaoDestinacao;
use App\Support\Estoque\DesviosAplicacao;
use App\Support\Estoque\MaterialParadoQuery;
use App\Support\Engenharia\GrdGerencialQuery;
use App\Support\Industrializacao\ResumoIndustrializacaoQuery;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Ciclo 21, Etapa 21.2 — "O que exige atenção agora?" — SUBSTITUI
 * `App\Support\Gestao\RiscoSuprimentoQuery` (21.1, removida — Seção 17
 * do pedido: "não duplicar risco"). Fonte ÚNICA de "Situação/Alerta"
 * (Seção 2): `Fato operacional → Regra gerencial → Situação/Alerta →
 * destinatários → canais` — esta classe cobre até "destinatários",
 * NUNCA implementa canais (Notification/e-mail/sino ficam pra 21.3).
 *
 * **Relação com `App\Support\Suprimentos\AlertaCadeiaSuprimento` (Ciclo
 * 19.7, pré-existente, intocada)**: aquela classe já dispara Notification
 * (`database`+`broadcast`) pra 2 fatos de granularidade LEGADA
 * (Pacote inteiro): risco projetado (`ItemSuprimento::necessidade()`/
 * `dataProjetadaAtendimento()`) e Pedido comercialmente atrasado. Esta
 * classe NUNCA duplica esses 2 disparos — cobre os MESMOS domínios com
 * granularidade mais fina (por Material, não só por Pacote — via
 * `CoberturaMaterialAtividadeQuery`, 21.1) e SEM nenhum canal de envio
 * (é derivação pura, consultável a qualquer momento, nunca "já
 * notificado"). As duas coexistem por ora: 19.7 é o mecanismo de ENVIO
 * já em produção pros 3 fatos legados; esta classe é a fundação da
 * FUTURA Central de Alertas mais rica, ainda sem nenhum canal.
 *
 * Toda situação nasce OBRA-ESCOPADA (Seção 11) e é 100% derivada — nunca
 * persistida (Seção 9): uma situação "desaparece" simplesmente porque a
 * PRÓXIMA chamada não a encontra mais, nunca porque alguém a "resolveu"
 * numa tabela.
 */
class SituacoesGerenciaisQuery
{
    public static function porObra(Work $obra, int $horizonteDias = 28): Collection
    {
        return collect()
            ->merge(self::materialCritico($obra, $horizonteDias))
            ->merge(self::reservaDescoberta($obra))
            ->merge(self::pedidoAtrasado($obra))
            ->merge(self::recebimentoPendente($obra))
            ->merge(self::materialSemDestinacao($obra))
            ->merge(self::saidaSemConciliacao($obra))
            ->merge(self::desvioAplicacao($obra))
            ->merge(self::inventarioAguardandoDecisao($obra))
            ->merge(self::documentoBloqueante($obra, $horizonteDias))
            ->merge(self::industrializacaoPendente($obra))
            ->merge(self::materialParado($obra))
            ->merge(self::grdAguardandoAceite($obra))
            ->unique(fn (SituacaoGerencial $s) => $s->chaveLogica) // deduplicação estrutural (Seção 8)
            ->sortBy(fn (SituacaoGerencial $s) => $s->chaveOrdenacao()) // prioridade (Seção 7)
            ->values();
    }

    // =========================================================
    // Destinatários conceituais (Seção 10) — nunca usuário inventado
    // =========================================================

    private const PERFIS_PLANEJAMENTO_SUPRIMENTOS = [
        ['slug' => 'planejamento.requisicoes', 'acao' => 'ver'],
        ['slug' => 'suprimentos.mapa', 'acao' => 'ver'],
    ];
    private const PERFIS_ALMOXARIFADO = [['slug' => 'estoque.movimentacao', 'acao' => 'ver']];
    private const PERFIS_CONCILIACAO = [['slug' => 'estoque.conciliacao', 'acao' => 'ver']];
    private const PERFIS_ENGENHARIA_PLANEJAMENTO = [
        ['slug' => 'engenharia.pacotes', 'acao' => 'ver'],
        ['slug' => 'planejamento.requisicoes', 'acao' => 'ver'],
    ];
    private const PERFIS_INVENTARIO_APROVACAO = [['slug' => 'estoque.inventario', 'acao' => 'editar']];
    private const PERFIS_INDUSTRIALIZACAO = [['slug' => 'estoque.industrializacao', 'acao' => 'ver']];

    /**
     * Resolve os perfis conceituais de uma `SituacaoGerencial` em
     * usuários REAIS da obra — mesmo padrão EXATO de
     * `App\Support\Suprimentos\AlertaCadeiaSuprimento::destinatarios()`
     * (Ciclo 19.7): sempre `$obra->users()->where('ativo', true)`,
     * filtrado por `temPermissaoNaObra()`, nunca um usuário hardcoded.
     * Usado só sob demanda (nunca dentro de `porObra()`, que fica
     * barata/read-only) — resolver usuários é 1 query adicional POR
     * situação exibida, não pra todas de uma vez.
     *
     * @return Collection<int, User>
     */
    public static function resolverDestinatarios(Work $obra, SituacaoGerencial $situacao): Collection
    {
        return self::resolverDestinatariosPorPerfis($obra, $situacao->destinatariosPerfis);
    }

    /**
     * Ciclo 21, Etapa 21.4 — extraído de `resolverDestinatarios()` pra
     * ser reaproveitado pelo Digest (`App\Console\Commands\
     * NotificarDigestSituacoesGerenciaisCommand`), que resolve
     * destinatários a partir de `App\Models\SituacaoOcorrencia` (só tem
     * `tipo`, não um `SituacaoGerencial` completo) — nunca duplica a
     * regra de filtro, só muda de onde vêm os perfis.
     *
     * @param  array<int, array{slug: string, acao: string}>  $perfis
     * @return Collection<int, User>
     */
    public static function resolverDestinatariosPorPerfis(Work $obra, array $perfis): Collection
    {
        if (empty($perfis)) {
            return collect();
        }

        return $obra->users()
            ->where('users.ativo', true)
            ->get()
            ->filter(function (User $user) use ($obra, $perfis) {
                foreach ($perfis as $perfil) {
                    if ($user->temPermissaoNaObra($obra, $perfil['slug'], $perfil['acao'])) {
                        return true;
                    }
                }

                return false;
            })
            ->unique('id')
            ->values();
    }

    /**
     * Ciclo 21, Etapa 21.4 — ÚNICA fonte da verdade de "quais perfis
     * conceituais recebem cada tipo" (Seção 13 do pedido 21.4:
     * "destinatários"). Espelha EXATAMENTE os `destinatariosPerfis`
     * passados pelos 11 métodos privados abaixo (`materialCritico()`
     * etc.) — nenhuma regra nova, só exposta como método público pra
     * quem (o Digest) só tem o `tipo` salvo em `SituacaoOcorrencia`,
     * nunca o `SituacaoGerencial` completo.
     *
     * @return array<int, array{slug: string, acao: string}>
     */
    public static function perfisParaTipo(TipoSituacaoGerencial $tipo): array
    {
        return match ($tipo) {
            TipoSituacaoGerencial::MaterialCritico,
            TipoSituacaoGerencial::PedidoAtrasado,
            TipoSituacaoGerencial::RecebimentoPendente,
            TipoSituacaoGerencial::MaterialSemDestinacao,
            TipoSituacaoGerencial::DesvioAplicacao => self::PERFIS_PLANEJAMENTO_SUPRIMENTOS,
            TipoSituacaoGerencial::ReservaDescoberta => array_merge(self::PERFIS_PLANEJAMENTO_SUPRIMENTOS, self::PERFIS_ALMOXARIFADO),
            TipoSituacaoGerencial::SaidaSemConciliacao => self::PERFIS_CONCILIACAO,
            TipoSituacaoGerencial::InventarioAguardandoDecisao => self::PERFIS_INVENTARIO_APROVACAO,
            TipoSituacaoGerencial::DocumentoBloqueante => self::PERFIS_ENGENHARIA_PLANEJAMENTO,
            TipoSituacaoGerencial::IndustrializacaoPendente => self::PERFIS_INDUSTRIALIZACAO,
            TipoSituacaoGerencial::MaterialParado => self::PERFIS_ALMOXARIFADO,
            TipoSituacaoGerencial::GrdAguardandoAceite => self::PERFIS_ENGENHARIA_PLANEJAMENTO,
        };
    }

    // =========================================================
    // Material crítico (via CoberturaMaterialAtividadeQuery, 21.1)
    // =========================================================

    private static function materialCritico(Work $obra, int $horizonteDias): Collection
    {
        $estadosAcionaveis = [
            EstadoCoberturaMaterial::SemCobertura,
            EstadoCoberturaMaterial::AguardandoCompra,
            EstadoCoberturaMaterial::DeficitAposConsumoEmergencial,
        ];

        return CoberturaMaterialAtividadeQuery::porObra($obra, $horizonteDias)
            ->flatMap(function (array $linha) use ($obra, $estadosAcionaveis) {
                return collect($linha['pares'])
                    ->filter(fn (array $par) => in_array($par['estado'], $estadosAcionaveis, true))
                    ->map(function (array $par) use ($obra, $linha) {
                        $diasParaInicio = (int) Carbon::today()->diffInDays($linha['inicio_planejado'], false);
                        $estado = $par['estado'];
                        $bloqueiaAgora = $estado === EstadoCoberturaMaterial::SemCobertura
                            || $estado === EstadoCoberturaMaterial::DeficitAposConsumoEmergencial;

                        return new SituacaoGerencial(
                            tipo: TipoSituacaoGerencial::MaterialCritico,
                            severidade: self::severidadeMaterialCritico($diasParaInicio, $estado),
                            obraId: $obra->id,
                            entidadeTipo: 'Atividade',
                            entidadeId: $linha['atividade_id'],
                            chaveLogica: "material_critico:{$linha['atividade_id']}:{$par['item_suprimento_id']}:{$par['material_id']}",
                            descricao: "Atividade {$linha['atividade_codigo']} inicia em {$diasParaInicio} dia(s) com material em estado '{$estado->label()}'.",
                            motivo: $estado->label(),
                            quantidade: round($par['demanda'] - $par['reservado_pacote'], 3),
                            dataRelevante: $linha['inicio_planejado'],
                            diasParaRelevante: $diasParaInicio,
                            impactoOperacional: $bloqueiaAgora ? 2 : 1,
                            destinatariosPerfis: self::PERFIS_PLANEJAMENTO_SUPRIMENTOS,
                            deepLink: ['rota' => 'radar.suprimentos', 'parametros' => ['pacote' => $par['item_suprimento_id']]],
                            contexto: $par + ['atividade_id' => $linha['atividade_id']],
                        );
                    });
            })
            ->values();
    }

    /**
     * Severidade calculada (Seção 6) — nunca escolhida manualmente:
     * cruza proximidade temporal × gravidade do estado de cobertura.
     */
    private static function severidadeMaterialCritico(int $diasParaInicio, EstadoCoberturaMaterial $estado): SeveridadeSituacao
    {
        $semCoberturaOuDeficit = $estado === EstadoCoberturaMaterial::SemCobertura
            || $estado === EstadoCoberturaMaterial::DeficitAposConsumoEmergencial;

        if ($diasParaInicio <= 7 && $semCoberturaOuDeficit) {
            return SeveridadeSituacao::Critica;
        }
        if ($diasParaInicio <= 7 || $semCoberturaOuDeficit) {
            return SeveridadeSituacao::Alta;
        }
        if ($diasParaInicio <= 14) {
            return SeveridadeSituacao::Atencao;
        }

        return SeveridadeSituacao::Atencao;
    }

    // =========================================================
    // Reserva descoberta (absorve "Recomposição Necessária")
    // =========================================================

    private static function reservaDescoberta(Work $obra): Collection
    {
        $paresBrutos = ReservaEstoque::query()
            ->where('obra_id', $obra->id)
            ->where('status', StatusReservaEstoque::Ativa->value)
            ->select('material_id', 'local_estoque_id')
            ->distinct()
            ->get();

        if ($paresBrutos->isEmpty()) {
            return collect();
        }

        $materiais = Material::whereIn('id', $paresBrutos->pluck('material_id')->unique())->get()->keyBy('id');
        $locais = LocalEstoque::whereIn('id', $paresBrutos->pluck('local_estoque_id')->unique())->get()->keyBy('id');

        $pares = $paresBrutos->map(fn ($p) => ['material' => $materiais[$p->material_id], 'local' => $locais[$p->local_estoque_id]]);

        return CoberturaReservas::porPares($pares)
            ->filter(fn (array $c) => $c['deficit'] > 0.0005)
            ->map(fn (array $c) => new SituacaoGerencial(
                tipo: TipoSituacaoGerencial::ReservaDescoberta,
                severidade: SeveridadeSituacao::Critica, // déficit físico real, nunca hipotético
                obraId: $obra->id,
                entidadeTipo: 'Material',
                entidadeId: $c['material_id'],
                chaveLogica: "reserva_descoberta:{$c['material_id']}:{$c['local_estoque_id']}",
                descricao: "Material {$materiais[$c['material_id']]->codigo} tem {$c['deficit']} unidade(s) reservada(s) sem cobertura física em {$locais[$c['local_estoque_id']]->nome}. Recomposição necessária: {$c['deficit']}.",
                motivo: 'reservado_ativo > fisico',
                quantidade: $c['deficit'],
                impactoOperacional: 2,
                destinatariosPerfis: array_merge(self::PERFIS_PLANEJAMENTO_SUPRIMENTOS, self::PERFIS_ALMOXARIFADO),
                deepLink: ['rota' => 'radar.estoque', 'parametros' => ['aba' => 'destinacao', 'material' => $c['material_id']]],
                contexto: $c,
            ))
            ->values();
    }

    // =========================================================
    // Pedido atrasado / Recebimento pendente
    // =========================================================

    private static function pedidoAtrasado(Work $obra): Collection
    {
        return self::pedidosEmitidosComItens($obra)
            ->map(fn (PedidoCompra $p) => [$p, $p->diasAtrasoAtual()])
            ->filter(fn (array $par) => $par[1] !== null && $par[1] > 0)
            ->map(function (array $par) use ($obra) {
                [$pedido, $dias] = $par;

                return new SituacaoGerencial(
                    tipo: TipoSituacaoGerencial::PedidoAtrasado,
                    severidade: $dias > 14 ? SeveridadeSituacao::Critica : SeveridadeSituacao::Alta,
                    obraId: $obra->id,
                    entidadeTipo: 'PedidoCompra',
                    entidadeId: $pedido->id,
                    chaveLogica: "pedido_atrasado:{$pedido->id}",
                    descricao: "Pedido de Compra Nº{$pedido->numero} está {$dias} dia(s) além da entrega prevista.",
                    motivo: 'data_prevista_entrega vencida sem entrega completa',
                    dataRelevante: $pedido->data_prevista_entrega,
                    impactoOperacional: 1,
                    diasAtraso: $dias,
                    destinatariosPerfis: self::PERFIS_PLANEJAMENTO_SUPRIMENTOS,
                    deepLink: ['rota' => 'radar.suprimentos', 'parametros' => ['pedido' => $pedido->id]],
                    contexto: ['numero' => $pedido->numero, 'dias_atraso' => $dias],
                );
            })
            ->values();
    }

    /**
     * Pedido Emitido, entrega ainda não completa, mas AINDA dentro do
     * prazo — informativo ("uma entrega está a caminho"), nunca
     * confundido com atraso.
     */
    private static function recebimentoPendente(Work $obra): Collection
    {
        return self::pedidosEmitidosComItens($obra)
            ->filter(fn (PedidoCompra $p) => $p->situacaoEntrega() !== SituacaoEntregaPedido::Completa && $p->diasAtrasoAtual() === null)
            ->map(function (PedidoCompra $pedido) use ($obra) {
                $saldo = round((float) $pedido->itens->sum(fn ($item) => $item->saldoAReceber()), 3);

                return new SituacaoGerencial(
                    tipo: TipoSituacaoGerencial::RecebimentoPendente,
                    severidade: SeveridadeSituacao::Informativa,
                    obraId: $obra->id,
                    entidadeTipo: 'PedidoCompra',
                    entidadeId: $pedido->id,
                    chaveLogica: "recebimento_pendente:{$pedido->id}",
                    descricao: "Pedido de Compra Nº{$pedido->numero} tem {$saldo} unidade(s) ainda não recebidas, dentro do prazo previsto.",
                    quantidade: $saldo,
                    dataRelevante: $pedido->data_prevista_entrega,
                    impactoOperacional: 0,
                    destinatariosPerfis: self::PERFIS_PLANEJAMENTO_SUPRIMENTOS,
                    deepLink: ['rota' => 'radar.suprimentos', 'parametros' => ['pedido' => $pedido->id]],
                    contexto: ['numero' => $pedido->numero, 'saldo_a_receber' => $saldo],
                );
            })
            ->values();
    }

    private static function pedidosEmitidosComItens(Work $obra): Collection
    {
        return PedidoCompra::query()
            ->where('obra_id', $obra->id)
            ->where('status', StatusPedidoCompra::Emitido->value)
            ->whereNotNull('data_prevista_entrega')
            ->with('itens.recebimentos')
            ->get();
    }

    // =========================================================
    // Material sem Destinação
    // =========================================================

    private static function materialSemDestinacao(Work $obra): Collection
    {
        $paresBrutos = \App\Models\AlocacaoRequisicaoPacote::query()
            ->whereHas('pacote', fn ($q) => $q->where('obra_id', $obra->id))
            ->with('requisicaoItem.itemTakeOff:id,material_id')
            ->get()
            ->map(fn ($a) => ['item_suprimento_id' => $a->item_suprimento_id, 'material_id' => $a->requisicaoItem?->itemTakeOff?->material_id])
            ->filter(fn ($p) => $p['material_id'] !== null)
            ->unique(fn ($p) => $p['item_suprimento_id'] . '|' . $p['material_id'])
            ->values();

        if ($paresBrutos->isEmpty()) {
            return collect();
        }

        $materiais = Material::whereIn('id', $paresBrutos->pluck('material_id')->unique())->get()->keyBy('id');

        return ConciliacaoDestinacao::porPares($paresBrutos)
            ->filter(fn (array $c) => $c['formal'] > 0.0005 && $c['saldo_a_destinar'] > 0.0005)
            ->map(fn (array $c) => new SituacaoGerencial(
                tipo: TipoSituacaoGerencial::MaterialSemDestinacao,
                severidade: SeveridadeSituacao::Atencao,
                obraId: $obra->id,
                entidadeTipo: 'ItemSuprimento',
                entidadeId: $c['item_suprimento_id'],
                chaveLogica: "material_sem_destinacao:{$c['item_suprimento_id']}:{$c['material_id']}",
                descricao: "Pacote tem {$c['saldo_a_destinar']} unidade(s) de {$materiais[$c['material_id']]->codigo} alocadas sem Destinação Planejada para nenhuma Frente.",
                quantidade: $c['saldo_a_destinar'],
                impactoOperacional: 0,
                destinatariosPerfis: self::PERFIS_PLANEJAMENTO_SUPRIMENTOS,
                deepLink: ['rota' => 'radar.estoque', 'parametros' => ['aba' => 'destinacao', 'pacote' => $c['item_suprimento_id']]],
                contexto: $c,
            ))
            ->values();
    }

    // =========================================================
    // Saída sem conciliação
    // =========================================================

    private static function saidaSemConciliacao(Work $obra): Collection
    {
        $pendencias = ConciliacaoAplicacao::pendenteAgregadaPorObra($obra->id)
            ->filter(fn (array $c) => $c['pendente'] > 0.0005)
            ->values();

        if ($pendencias->isEmpty()) {
            return collect();
        }

        $materiais = Material::whereIn('id', $pendencias->pluck('material_id')->unique())->get()->keyBy('id');

        return $pendencias
            ->map(fn (array $c) => new SituacaoGerencial(
                tipo: TipoSituacaoGerencial::SaidaSemConciliacao,
                severidade: SeveridadeSituacao::Atencao,
                obraId: $obra->id,
                entidadeTipo: 'Material',
                entidadeId: $c['material_id'],
                chaveLogica: "saida_sem_conciliacao:{$c['material_id']}",
                descricao: "Material {$materiais[$c['material_id']]?->codigo}: {$c['pendente']} unidade(s) saíram do estoque sem aplicação registrada.",
                quantidade: $c['pendente'],
                impactoOperacional: 0,
                destinatariosPerfis: self::PERFIS_CONCILIACAO,
                deepLink: ['rota' => 'radar.estoque', 'parametros' => ['aba' => 'conciliacao', 'material' => $c['material_id']]],
                contexto: $c,
            ))
            ->values();
    }

    // =========================================================
    // Desvio de Aplicação (Planejado x Real — nunca acusatório)
    // =========================================================

    private static function desvioAplicacao(Work $obra): Collection
    {
        $saidas = MovimentacaoEstoque::query()
            ->where('obra_id', $obra->id)
            ->whereNotNull('reserva_estoque_id')
            ->get();

        return DesviosAplicacao::porSaidasEmLote($saidas)
            ->filter(fn (array $d) => $d['tem_frente_planejada'] && $d['total_desviado'] > 0.0005)
            ->map(function (array $d, string $saidaId) use ($obra) {
                return new SituacaoGerencial(
                    tipo: TipoSituacaoGerencial::DesvioAplicacao,
                    severidade: SeveridadeSituacao::Atencao,
                    obraId: $obra->id,
                    entidadeTipo: 'MovimentacaoEstoque',
                    entidadeId: $saidaId,
                    chaveLogica: "desvio_aplicacao:{$saidaId}",
                    // Linguagem factual, nunca acusatória (Seção 14).
                    descricao: "Aplicação diferente da destinação planejada: {$d['total_desviado']} unidade(s).",
                    quantidade: $d['total_desviado'],
                    impactoOperacional: 0,
                    destinatariosPerfis: self::PERFIS_PLANEJAMENTO_SUPRIMENTOS,
                    deepLink: ['rota' => 'radar.estoque', 'parametros' => ['aba' => 'conciliacao', 'saida' => $saidaId]],
                    contexto: $d,
                );
            })
            ->values();
    }

    // =========================================================
    // Inventário aguardando decisão
    // =========================================================

    private static function inventarioAguardandoDecisao(Work $obra): Collection
    {
        return InventarioEstoque::query()
            ->where('obra_id', $obra->id)
            ->where('status', StatusInventarioEstoque::EmAnalise->value)
            ->with('localEstoque:id,nome')
            ->get()
            ->map(fn (InventarioEstoque $inv) => new SituacaoGerencial(
                tipo: TipoSituacaoGerencial::InventarioAguardandoDecisao,
                severidade: SeveridadeSituacao::Alta,
                obraId: $obra->id,
                entidadeTipo: 'InventarioEstoque',
                entidadeId: $inv->id,
                chaveLogica: "inventario_aguardando_decisao:{$inv->id}",
                descricao: "Inventário \"{$inv->titulo}\" ({$inv->localEstoque?->nome}) está em análise, aguardando aprovação de ajuste.",
                impactoOperacional: 0,
                destinatariosPerfis: self::PERFIS_INVENTARIO_APROVACAO,
                deepLink: ['rota' => 'radar.estoque', 'parametros' => ['aba' => 'inventario', 'inventario' => $inv->id]],
                contexto: ['status' => $inv->status->value],
            ))
            ->values();
    }

    // =========================================================
    // Documento bloqueante
    // =========================================================

    private static function documentoBloqueante(Work $obra, int $horizonteDias): Collection
    {
        $referencia = Carbon::today();
        $fim = $referencia->copy()->addDays($horizonteDias);

        $documentos = DocumentoEngenharia::query()
            ->where('obra_id', $obra->id)
            ->naoLiberados()
            ->whereHas('atividades', function ($q) use ($referencia, $fim) {
                $q->where('fora_do_cronograma', false)
                    ->where('status', '!=', StatusAtividade::Concluido->value)
                    ->whereBetween('inicio_planejado', [$referencia->toDateString(), $fim->toDateString()]);
            })
            ->with([
                'atividades' => function ($q) use ($referencia, $fim) {
                    $q->where('fora_do_cronograma', false)
                        ->where('status', '!=', StatusAtividade::Concluido->value)
                        ->whereBetween('inicio_planejado', [$referencia->toDateString(), $fim->toDateString()]);
                },
                // Ciclo 22, Etapa 22.2 — achado real, corrigido aqui: `motivo:`
                // e `contexto['motivo_liberacao']` abaixo chamam `$doc->
                // motivoLiberacao()` (2x por documento), que internamente lê
                // `revisaoVigente()` — sem este eager-load, `revisaoVigente()`
                // cai no fallback `$this->latestRevisao()->first()` (uma
                // query NOVA a cada chamada, nunca cacheada — não é lazy
                // loading no sentido que `Model::preventLazyLoading()`
                // intercepta, por isso nunca lançava exceção em teste, só
                // rodava silenciosamente). Com N documentos bloqueantes isso
                // é 2N queries extras — exposto pela primeira vez em escala
                // pelo Cockpit de Engenharia (Seção 28 do pedido 22.2, "não
                // aceitar query por atividade/documento"). Mesmo eager-load
                // já usado com sucesso em `ProntidaoDocumentalAtividadeQuery`
                // (Ciclo 22.1) e corrigido antes em `CentralProntidaoQuery`
                // (Ciclo 21.7.CORREÇÃO, Achado C) — mesma classe de bug.
                'latestRevisao.ultimaLiberacao',
            ])
            ->get();

        return $documentos
            ->flatMap(fn (DocumentoEngenharia $doc) => $doc->atividades->map(fn (Atividade $ativ) => [$doc, $ativ]))
            ->map(function (array $par) use ($obra) {
                [$doc, $ativ] = $par;
                $diasParaInicio = (int) Carbon::today()->diffInDays($ativ->inicio_planejado, false);

                return new SituacaoGerencial(
                    tipo: TipoSituacaoGerencial::DocumentoBloqueante,
                    severidade: $diasParaInicio <= 7 ? SeveridadeSituacao::Alta : SeveridadeSituacao::Atencao,
                    obraId: $obra->id,
                    entidadeTipo: 'DocumentoEngenharia',
                    entidadeId: $doc->id,
                    chaveLogica: "documento_bloqueante:{$doc->id}:{$ativ->id}",
                    descricao: "Documento {$doc->codigo} não está liberado para construção e a Atividade {$ativ->codigo_cronograma} inicia em {$diasParaInicio} dia(s).",
                    motivo: $doc->motivoLiberacao(),
                    dataRelevante: $ativ->inicio_planejado,
                    diasParaRelevante: $diasParaInicio,
                    impactoOperacional: 1,
                    destinatariosPerfis: self::PERFIS_ENGENHARIA_PLANEJAMENTO,
                    // Ciclo 21, Etapa 21.3 — achado: 'engenharia.documentos' nunca
                    // existiu como nome de rota (confirmado por grep em routes/web.php);
                    // a Lista de Documentos de Engenharia é 'engenharia.pacotes'
                    // (mesmo padrão de seletor de obra próprio, sem dependência de
                    // ObraContext de sessão — CLAUDE.md, Ciclo 18). Corrigido aqui
                    // porque só agora (21.3) o deep-link vira navegação REAL.
                    deepLink: ['rota' => 'engenharia.pacotes', 'parametros' => ['documento' => $doc->id]],
                    contexto: ['atividade_id' => $ativ->id, 'motivo_liberacao' => $doc->motivoLiberacao()],
                );
            })
            ->values();
    }

    // =========================================================
    // GRD aguardando aceite (Ciclo 22, Etapa 22.3 — único fato de
    // Engenharia promovido ao motor global após o inventário)
    // =========================================================

    /**
     * Delega 100% pra `GrdGerencialQuery::aguardandoAceite()` (Ciclo
     * 22.1, já batch, já testado) — nunca reimplementa a regra de
     * "aceite ativo" (`GrdAceiteEntrega::estaAtivo()`). Só adapta o
     * `FatoEngenharia` (shape idêntico por design, Ciclo 22.1) pra
     * `SituacaoGerencial`, incluindo a `chaveLogica` estável por
     * destinatário (nunca por texto/timestamp, Seção 17 do pedido).
     * Severidade sempre `Informativa` — sem prazo/SLA formal no domínio
     * (confirmado por fresh-read, 22.1/22.3), nunca `Alta`/`Critica`.
     */
    private static function grdAguardandoAceite(Work $obra): Collection
    {
        return GrdGerencialQuery::aguardandoAceite($obra)
            ->map(fn ($fato) => new SituacaoGerencial(
                tipo: TipoSituacaoGerencial::GrdAguardandoAceite,
                severidade: $fato->severidade,
                obraId: $obra->id,
                entidadeTipo: $fato->entidadeTipo,
                entidadeId: $fato->entidadeId,
                chaveLogica: "grd_aguardando_aceite:{$fato->entidadeId}",
                descricao: $fato->descricao,
                dataRelevante: $fato->dataRelevante,
                diasParaRelevante: $fato->diasParaRelevante,
                impactoOperacional: 0,
                destinatariosPerfis: self::PERFIS_ENGENHARIA_PLANEJAMENTO,
                deepLink: $fato->deepLink,
                contexto: $fato->contexto,
            ))
            ->values();
    }

    // =========================================================
    // Industrialização pendente (nunca "atrasada" sem prazo formal)
    // =========================================================

    private static function industrializacaoPendente(Work $obra): Collection
    {
        return ResumoIndustrializacaoQuery::porObra($obra->id)
            ->filter(fn (array $r) => $r['pendente'] > 0.0005)
            ->map(fn (array $r) => new SituacaoGerencial(
                tipo: TipoSituacaoGerencial::IndustrializacaoPendente,
                severidade: SeveridadeSituacao::Informativa,
                obraId: $obra->id,
                entidadeTipo: 'OrdemIndustrializacao',
                entidadeId: $r['ordem_industrializacao_id'],
                chaveLogica: "industrializacao_pendente:{$r['ordem_industrializacao_id']}",
                descricao: "Ordem de Industrialização tem {$r['pendente']} unidade(s) previstas ainda não entregues (prazo de produção: desconhecido).",
                quantidade: $r['pendente'],
                impactoOperacional: 0,
                destinatariosPerfis: self::PERFIS_INDUSTRIALIZACAO,
                deepLink: ['rota' => 'radar.estoque', 'parametros' => ['aba' => 'industrializacao', 'ordem' => $r['ordem_industrializacao_id']]],
                contexto: $r,
            ))
            ->values();
    }

    // =========================================================
    // Material parado (threshold parametrizável)
    // =========================================================

    private static function materialParado(Work $obra, int $thresholdDias = 60): Collection
    {
        $materialIds = MovimentacaoEstoque::query()
            ->where('obra_id', $obra->id)
            ->distinct()
            ->pluck('material_id')
            ->all();

        if (empty($materialIds)) {
            return collect();
        }

        $materiais = Material::whereIn('id', $materialIds)->get()->keyBy('id');
        $locaisIds = LocalEstoque::where('obra_id', $obra->id)->pluck('id', 'id');

        return MaterialParadoQuery::porMateriaisNaObra($materialIds, $obra->id)
            ->filter(fn (array $m) => $m['dias_sem_movimentacao'] >= $thresholdDias)
            ->map(function (array $m) use ($obra, $materiais) {
                return new SituacaoGerencial(
                    tipo: TipoSituacaoGerencial::MaterialParado,
                    severidade: SeveridadeSituacao::Informativa,
                    obraId: $obra->id,
                    entidadeTipo: 'Material',
                    entidadeId: $m['material_id'],
                    chaveLogica: "material_parado:{$m['material_id']}:{$m['local_estoque_id']}",
                    descricao: "Material {$materiais[$m['material_id']]?->codigo} está sem movimentação há {$m['dias_sem_movimentacao']} dia(s).",
                    motivo: 'sem_movimentacao_recente',
                    quantidade: null,
                    impactoOperacional: 0,
                    destinatariosPerfis: self::PERFIS_ALMOXARIFADO,
                    deepLink: ['rota' => 'radar.estoque', 'parametros' => ['aba' => 'movimentacoes', 'material' => $m['material_id']]],
                    contexto: $m,
                );
            })
            ->values();
    }
}
