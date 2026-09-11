<?php

namespace App\Support;

use App\Enums\PilarLean;
use App\Enums\StatusRequisicaoCompra;
use App\Enums\StatusRestricao;
use App\Models\Atividade;
use App\Models\CategoriaRestricao;
use App\Models\ItemSuprimento;
use App\Models\Restricao;
use App\Support\Estoque\CoberturaNecessidadeAtividadeQuery;
use App\Support\Suprimentos\AlertaCadeiaSuprimento;
use App\Support\Suprimentos\ConciliacaoRecebimento;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ciclo 19, Etapa 19.7 — ponte entre a CADEIA FORMAL de Suprimentos
 * (RP→Pacote→RC→Pedido→Recebimento, Ciclo 19) e o motor canônico de
 * Restrição/prontidão já existente (`Atividade::estaPronta()`/
 * `scopeProntas()`, intocados — a mudança de prontidão acontece só
 * porque uma Restrição bloqueante aberta já é regra canônica).
 *
 * **Deliberadamente PARALELO e NUNCA sincronizado com
 * `App\Support\SincronizarRestricaoSuprimento`** (mecanismo legado,
 * baseado em `ItemSuprimentoEtapa`/`SuprimentoScheduler`/
 * `ItemSuprimento.status`) — decisão do usuário (investigação da 19.7):
 * os dois mecanismos NUNCA leem nem escrevem a Restrição um do outro.
 * Identidade estrutural DEDICADA: `Restricao.origem_cadeia_suprimento_id`
 * (nunca `origem_suprimento_item_id`, que pertence exclusivamente ao
 * legado) — ver migration `2026_08_25_000001_...` e
 * `App\Models\Restricao::origemCadeiaSuprimento()`.
 *
 * **3 estados conceituais, nunca tratados como um só "atraso"**:
 * - Risco projetado (Alerta A, `AlertaCadeiaSuprimento::
 *   dispararRiscoProjetado()`): `dataProjetadaAtendimento() > necessidade()`
 *   — nunca cria/mexe em Restrição.
 * - Atraso comercial do Pedido (Alerta B, `dispararPedidoAtrasado()`):
 *   `PedidoCompra::diasAtrasoAtual() !== null` — nunca cria/mexe em
 *   Restrição sozinho, mesmo quando a necessidade ainda é futura.
 * - Falta efetiva na necessidade (Condição C, ESTE serviço): única
 *   condição que abre/mantém uma Restrição bloqueante.
 *
 * **Granularidade — Atividade + Pacote** (decisão do usuário): 1
 * Restrição automática por par, nunca por Pedido/RC/Recebimento
 * individual (vários Pedidos do mesmo Pacote são só CONTEXTO/evidência,
 * nunca causas separadas). Atividade avaliada INDIVIDUALMENTE pela sua
 * própria `inicio_planejado` (nunca o MIN consolidado de
 * `ItemSuprimento::necessidade()`, que NÃO é tocado por esta etapa) —
 * um Pacote compartilhado por A1 (vencida) e A2 (futura) só bloqueia A1.
 * Múltiplos Pacotes na mesma Atividade geram Restrições INDEPENDENTES
 * (causas distintas, nunca colapsadas numa só).
 *
 * **Condição C = "material formal pendente" (limitação documentada,
 * decisão do usuário confirmada na investigação — não inventar rateio
 * de quantidade por Atividade)**: o vínculo Pacote↔Atividade informa só
 * a necessidade TEMPORAL, nunca qual fatia da quantidade pertence a cada
 * Atividade — não existe hoje nenhuma coluna que faça esse rateio. Regra
 * adotada, explicitamente conservadora: SE o Pacote ainda tem QUALQUER
 * material formal pendente (`ConciliacaoRecebimento::porPacote()`,
 * `itens_nao_recebidos + itens_parciais > 0`, escopado a Pedidos já
 * EMITIDOS) E a Atividade já atingiu sua própria necessidade, a
 * Atividade recebe a Restrição — mesmo que o Pacote também atenda outra
 * Atividade cuja necessidade já foi 100% satisfeita por um recebimento
 * anterior. **Limitação residual documentada, não corrigida nesta
 * fase**: uma RC Emitida SEM nenhum Pedido ainda (formal demand
 * requisitada mas nunca sequer comprada) não conta como "pendente" por
 * este critério — `ConciliacaoRecebimento::porPacote()` só enxerga a
 * camada Pedido→Recebimento, não RP/RC sozinhas.
 *
 * **Resolução/reabertura — mesmo padrão exato de
 * `SincronizarRestricaoSuprimento::abrirOuAtualizar()`/
 * `resolverAutomaticamente()`**: a Restrição NUNCA é deletada — sempre a
 * MESMA linha (garantida estruturalmente pelo
 * `UNIQUE(tenant_id, origem_cadeia_suprimento_id, atividade_id)`), só
 * `status` alterna Aberta↔Resolvida. Reprogramar a necessidade pra
 * antes de hoje enquanto o material ainda está pendente reabre a MESMA
 * Restrição (novo episódio, `aberta_em` atualizado — usado como parte
 * da chave de idempotência do Alerta C); reprogramar pra depois de hoje
 * (ou o recebimento completar a demanda) resolve.
 *
 * **Restrição manual nunca é tocada** — a busca é sempre filtrada por
 * `origem_cadeia_suprimento_id` explícito, nunca por texto/descrição
 * (nenhuma Restrição manual tem esse campo preenchido).
 */
class SincronizarRestricaoCadeiaSuprimento
{
    private const STATUS_ABERTOS = [
        StatusRestricao::Aberta->value,
        StatusRestricao::EmTratamento->value,
        StatusRestricao::AguardandoTerceiros->value,
    ];

    private static ?AlertaCadeiaSuprimento $alerta = null;

    /**
     * Ponto de entrada usado por eventos pontuais (reimportação de
     * cronograma, emissão de Pedido, recebimento) — resolve os Pacotes
     * tocados pelas atividades informadas e ressincroniza cada um por
     * inteiro (mesma disciplina de `SincronizarRestricaoSuprimento::
     * aplicarParaAtividades()`).
     *
     * @param  iterable<string>  $atividadeIds
     */
    public static function aplicarParaAtividades(iterable $atividadeIds, ?string $userId): void
    {
        $ids = collect($atividadeIds)->values();
        if ($ids->isEmpty()) {
            return;
        }

        $pacotes = ItemSuprimento::whereHas('atividades', function ($query) use ($ids) {
            $query->whereIn('atividades.id', $ids);
        })->get();

        foreach ($pacotes as $pacote) {
            static::sincronizarPacote($pacote, $userId);
        }
    }

    /**
     * Ressincroniza TODAS as Atividades de UM Pacote — ponto de entrada
     * usado pelo Scheduler (rede de segurança pra deriva pura de tempo,
     * sem nenhum model mudar) e por qualquer evento direto.
     */
    public static function sincronizarPacote(ItemSuprimento $pacote, ?string $userId): void
    {
        $pacote = $pacote->fresh(['atividades']);

        $resumo = ConciliacaoRecebimento::porPacote($pacote);
        $temCadeiaFormalEmUso = $resumo['total_pedidos'] > 0;
        $temMaterialPendente = $temCadeiaFormalEmUso
            && ($resumo['itens_nao_recebidos'] + $resumo['itens_parciais']) > 0;

        $hoje = Carbon::today();

        foreach ($pacote->atividades as $atividade) {
            $restricao = static::buscarRestricao($pacote, $atividade);

            if ($atividade->fora_do_cronograma || ! $atividade->inicio_planejado) {
                static::resolverSeAberta($restricao, $userId);
                continue;
            }

            $necessidade = $atividade->inicio_planejado;
            $condicaoC = $temMaterialPendente && $hoje->gte($necessidade);

            // Correção Segura do Falso Positivo (Revisão Arquitetural 2,
            // Seção 13-17) — mesmo princípio já aplicado em
            // `SincronizarRestricaoSuprimento::sincronizarItem()`: a
            // Condição C (material formal ainda não recebido) é sempre
            // sobre o processo COMERCIAL do Pacote, nunca sobre se a
            // necessidade específica de Material desta Atividade já
            // está fisicamente coberta por Estoque/Reserva. Sem
            // correspondência inequívoca (`null`), o comportamento
            // histórico é preservado.
            $cobertoPorEstoque = CoberturaNecessidadeAtividadeQuery::estadoCobreTodasParaPacote($atividade, $pacote);
            $bloquearPorFaltaDeMaterial = $condicaoC && $cobertoPorEstoque !== true;

            if ($bloquearPorFaltaDeMaterial) {
                static::abrirOuManter($pacote, $atividade, $restricao, $necessidade);
            } else {
                static::resolverSeAberta($restricao, $userId, $condicaoC ? 'cobertura' : 'prazo');
            }
        }
    }

    private static function buscarRestricao(ItemSuprimento $pacote, Atividade $atividade): ?Restricao
    {
        return Restricao::where('atividade_id', $atividade->id)
            ->where('origem_cadeia_suprimento_id', $pacote->id)
            ->first();
    }

    private static function abrirOuManter(ItemSuprimento $pacote, Atividade $atividade, ?Restricao $restricao, Carbon $necessidade): void
    {
        $descricao = "Material do Pacote \"{$pacote->nome}\" ainda não recebido para a data de necessidade da atividade.";

        if ($restricao && in_array($restricao->status->value, self::STATUS_ABERTOS, true)) {
            // já aberta — só atualiza prazo/descrição se algo mudou,
            // nunca reabre nem dispara Alerta C de novo (não é episódio novo).
            if (! $restricao->prazo_limite?->isSameDay($necessidade) || $restricao->descricao !== $descricao) {
                $restricao->update(['prazo_limite' => $necessidade, 'descricao' => $descricao]);
            }

            return;
        }

        if ($restricao) {
            // Existia e estava resolvida — a causa voltou: reabre a MESMA
            // linha (nunca cria uma segunda), novo episódio.
            $restricao->update([
                'status' => StatusRestricao::Aberta->value,
                'prazo_limite' => $necessidade,
                'aberta_em' => now(),
                'resolvida_em' => null,
                'descricao' => $descricao,
            ]);

            static::dispararAlertaAposCommit($restricao->fresh(), $pacote, $atividade);

            return;
        }

        $nova = Restricao::create([
            'atividade_id' => $atividade->id,
            'categoria_id' => static::categoriaMateriais($pacote->tenant_id)->id,
            'descricao' => $descricao,
            'bloqueante' => true,
            'prazo_limite' => $necessidade,
            'status' => StatusRestricao::Aberta->value,
            'aberta_em' => now(),
            'origem_cadeia_suprimento_id' => $pacote->id,
        ]);

        static::dispararAlertaAposCommit($nova, $pacote, $atividade);
    }

    /**
     * Seção 44 do pedido: "domínio/restrição commit → Notification
     * afterCommit. Não misturar side-effect externo dentro da transaction
     * principal." A ESCRITA da Restrição já aconteceu de forma normal
     * (síncrona, acima) — só o disparo da Notification (job de fila) é
     * adiado. `DB::afterCommit()` executa IMEDIATAMENTE quando não há
     * transação em andamento (chamada a partir do Scheduler, sem
     * transação externa) — o mesmo código fica correto nos dois
     * contextos (dentro de uma transação de Pedido/Recebimento, ou solto
     * a partir do Command diário).
     */
    private static function dispararAlertaAposCommit(Restricao $restricao, ItemSuprimento $pacote, Atividade $atividade): void
    {
        DB::afterCommit(function () use ($restricao, $pacote, $atividade) {
            try {
                static::alerta()->dispararRestricaoCriada($restricao, $pacote, $atividade);
            } catch (\Throwable $e) {
                report($e);
            }
        });
    }

    private static function resolverSeAberta(?Restricao $restricao, ?string $userId, string $motivo = 'prazo'): void
    {
        if (! $restricao || ! in_array($restricao->status->value, self::STATUS_ABERTOS, true)) {
            return;
        }

        if ($userId) {
            $descricao = $motivo === 'cobertura'
                ? 'Restrição resolvida automaticamente: a necessidade de Material desta atividade já está coberta por estoque/reserva específica (o Pacote pode continuar com material formal pendente comercialmente).'
                : 'Restrição resolvida automaticamente: material da cadeia formal de Suprimentos recebido ou necessidade não mais vigente.';

            $restricao->acoes()->create([
                'autor_id' => $userId,
                'descricao' => $descricao,
            ]);
        }

        $restricao->update([
            'status' => StatusRestricao::Resolvida->value,
            'resolvida_em' => now(),
        ]);
    }

    private static function categoriaMateriais(string $tenantId): CategoriaRestricao
    {
        return CategoriaRestricao::where('tenant_id', $tenantId)
            ->where('pilar_lean', PilarLean::Materiais->value)
            ->first() ?? CategoriaRestricao::create([
                'tenant_id' => $tenantId,
                'nome' => 'Suprimentos',
                'pilar_lean' => PilarLean::Materiais->value,
            ]);
    }

    private static function alerta(): AlertaCadeiaSuprimento
    {
        return static::$alerta ??= new AlertaCadeiaSuprimento();
    }
}
