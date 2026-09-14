<?php

namespace App\Support\Gestao;

use App\DTOs\Gestao\Home\HomeExecutiva;
use App\DTOs\Gestao\Home\HomeHorizonte;
use App\Enums\EstadoGerencialNecessidade;
use App\Enums\StatusRestricao;
use App\Models\AtividadeNecessidadeMaterial;
use App\Models\Atividade;
use App\Models\CausaNaoCumprimento;
use App\Models\PedidoCompraPrevisaoEntrega;
use App\Models\ProgramacaoSemanalItem;
use App\Models\RecebimentoPedido;
use App\Models\Restricao;
use App\Models\RevisaoLiberacao;
use App\Models\Work;
use App\Support\CentralProntidao\AtividadeProntidaoView;
use App\Support\CentralProntidao\OrigemRestricaoProntidao;
use App\Support\CentralProntidao\StatusOperacionalProntidao;
use App\Support\CentralProntidao\CentralProntidaoQuery;
use App\Support\Suprimentos\EstadoAtendimentoNecessidadeMaterialQuery;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Home Executiva (Ciclo 25) — read model ÚNICO da página inicial da
 * plataforma. Fonte a partir de fatos JÁ EXISTENTES, nunca uma segunda
 * verdade:
 *
 * - Prontidão operacional (hero, horizontes, ameaças, causas, matriz
 *   recuperável) vem 100% de `Atividade::scopeProntas()` via
 *   `App\Support\CentralProntidao\CentralProntidaoQuery::paraObra()` —
 *   nunca recalculada aqui. A hard readiness (`estaPronta()`) NUNCA é
 *   alterada nem reimplementada por esta classe.
 * - Exposição de Suprimentos por NECESSIDADE (Motor Definitivo de Risco
 *   de Suprimentos V1) vem 100% de `App\Support\Suprimentos\
 *   EstadoAtendimentoNecessidadeMaterialQuery::porAtividade()` — este
 *   sinal é deliberadamente INFORMATIVO: nunca bloqueia `pronta` (a
 *   Central de Prontidão e o hard readiness continuam ignorando-o, por
 *   design já estabelecido no domínio — ver
 *   `PlanoSemanalPostoOperacionalTest`).
 * - PPC/Execução da Semana reaproveita a MESMA fórmula corrigida no
 *   Ciclo 24 (`⚡relatorios-restricoes.blade.php::ppcQuery()` — join contra
 *   a versão vigente de `programacoes_semanais`, `concluido_em <=
 *   semana_fim`), reimplementada aqui como método próprio (nunca extraída
 *   de dentro do componente Livewire, que é uma classe anônima) — mesma
 *   convenção já usada no projeto pra pequenas duplicações de lógica
 *   entre páginas (ver CLAUDE.md, "não compartilhar helper pequeno via
 *   trait").
 *
 * **Ranking de "ameaças" e "ações recomendadas" é apresentação, nunca uma
 * nova regra de negócio** — critério documentado em `montarAmeacas()`/
 * `montarAcoesRecomendadas()`.
 *
 * **Escopo desta primeira versão, honestamente reduzido** (ver relatório
 * de entrega): "O que mudou desde a última vez" usa uma janela fixa de 7
 * dias (nunca "desde o último acesso" — não existe hoje nenhum tracking
 * de último acesso por usuário/obra); "O sistema já sabia" usa somente o
 * sinal de Restrição bloqueante já aberta antes do início da semana
 * (nunca Suprimento/Engenharia, por recorte de tempo desta entrega);
 * ações recomendadas nunca disparam mutação — são sempre deep-links pra
 * telas com a Action real (Seção 17 do pedido: "quando SEGURO e JÁ
 * EXISTIR Action adequada" — replicar validação de domínio na Home seria
 * duplicar regra, então preferimos o deep-link).
 */
class HomeExecutivaQuery
{
    private const DIAS_HERO = 14;

    private const DIAS_JANELA_RECENTE = 7;

    private const MAX_AMEACAS = 5;

    private const MAX_ACOES = 5;

    private const MAX_ACONTECIMENTOS = 8;

    /** Estados do Motor V1 tratados como exposição séria (ainda não bloqueia hard readiness, mas ameaça a execução). */
    private const ESTADOS_MOTOR_RISCO = [
        EstadoGerencialNecessidade::DependenteFornecimentoAtrasado,
        EstadoGerencialNecessidade::SemPrazo,
        EstadoGerencialNecessidade::NaoContratada,
    ];

    /**
     * @param  Carbon|null  $ultimoAcessoAnterior  cursor de "última vez que
     *     este usuário abriu a Home desta obra", já capturado (e avançado
     *     para agora) por `App\Support\Gestao\UltimoAcessoHomeTracker`
     *     ANTES desta chamada — `null` = primeiro acesso desta obra por
     *     este usuário (ou chamador que não rastreia visita, ex.: testes).
     */
    public static function resumo(Work $obra, ?Carbon $ultimoAcessoAnterior = null): HomeExecutiva
    {
        $hoje = Carbon::today();

        $temCronograma = Atividade::where('obra_id', $obra->id)
            ->where('fora_do_cronograma', false)
            ->exists();

        if (! $temCronograma) {
            return self::montarVazio($obra);
        }

        $maiorHorizonteAte = $hoje->copy()->addDays(56);
        $todasNoHorizonte = (new CentralProntidaoQuery())->paraObra($obra, $maiorHorizonteAte);

        $horizontes = self::montarHorizontes($todasNoHorizonte, $hoje);
        $hero = $horizontes['duas_semanas'];

        $cutoffHero = $hoje->copy()->addDays(self::DIAS_HERO);
        $viewsHero = $todasNoHorizonte
            ->filter(fn (AtividadeProntidaoView $v) => $v->inicioPlanejado && $v->inicioPlanejado->lte($cutoffHero))
            ->values();

        $motorPorAtividade = self::carregarMotorV1($viewsHero);

        $causas = self::montarCausas($viewsHero, $motorPorAtividade);
        $ameacas = self::montarAmeacas($viewsHero, $motorPorAtividade, $hoje);
        $suprimentos = self::montarSuprimentosExecucao($motorPorAtividade);
        $engenharia = self::montarEngenhariaExecucao($viewsHero);
        $recuperavel = self::montarProntidaoRecuperavel($viewsHero, $hero);
        $acoes = self::montarAcoesRecomendadas($viewsHero, $motorPorAtividade);

        $primeiroAcesso = $ultimoAcessoAnterior === null;
        $desdeUltimaVisita = $ultimoAcessoAnterior ?? $hoje->copy()->subDays(self::DIAS_JANELA_RECENTE);
        $acontecimentos = self::montarUltimosAcontecimentos($obra, $hoje, $desdeUltimaVisita);

        $execucaoSemana = self::montarExecucaoSemana($obra, $hoje);
        $frase = self::montarFraseGerencial($hero, $causas);

        return new HomeExecutiva(
            obraId: $obra->id,
            obraNome: $obra->name,
            temCronograma: true,
            heroProntidao: $hero,
            fraseGerencial: $frase,
            ameacas: $ameacas,
            horizontes: $horizontes,
            causas: $causas,
            suprimentos: $suprimentos,
            engenharia: $engenharia,
            acoesRecomendadas: $acoes,
            ultimosAcontecimentos: $acontecimentos,
            execucaoSemana: $execucaoSemana,
            prontidaoRecuperavel: $recuperavel,
            primeiroAcessoHome: $primeiroAcesso,
        );
    }

    private static function montarVazio(Work $obra): HomeExecutiva
    {
        $horizonteVazio = fn (string $chave, string $label, int $dias) => new HomeHorizonte($chave, $label, $dias, 0, 0, 0, 0, null);

        return new HomeExecutiva(
            obraId: $obra->id,
            obraNome: $obra->name,
            temCronograma: false,
            heroProntidao: $horizonteVazio('duas_semanas', 'Próximas 2 semanas', self::DIAS_HERO),
            fraseGerencial: 'Importe o cronograma desta obra para começar a enxergar a prontidão das próximas semanas.',
            ameacas: [],
            horizontes: [
                'semana' => $horizonteVazio('semana', 'Esta semana', 7),
                'duas_semanas' => $horizonteVazio('duas_semanas', '+2 semanas', 14),
                'quatro_semanas' => $horizonteVazio('quatro_semanas', '+4 semanas', 28),
                'oito_semanas' => $horizonteVazio('oito_semanas', '+8 semanas', 56),
            ],
            causas: [],
            suprimentos: self::suprimentosVazio(),
            engenharia: ['total_atividades' => 0, 'documentos' => [], 'frase' => 'Sem documentos vinculados nas próximas semanas.'],
            acoesRecomendadas: [],
            ultimosAcontecimentos: [],
            execucaoSemana: null,
            prontidaoRecuperavel: ['percentual_atual' => null, 'percentual_potencial' => null, 'atividades_recuperaveis' => 0, 'frase' => null],
        );
    }

    /**
     * @return array<string, HomeHorizonte>
     */
    private static function montarHorizontes(Collection $views, Carbon $hoje): array
    {
        $definicoes = [
            'semana' => ['Esta semana', $hoje->copy()->endOfWeek()],
            'duas_semanas' => ['+2 semanas', $hoje->copy()->addDays(14)],
            'quatro_semanas' => ['+4 semanas', $hoje->copy()->addDays(28)],
            'oito_semanas' => ['+8 semanas', $hoje->copy()->addDays(56)],
        ];

        $resultado = [];
        foreach ($definicoes as $chave => [$label, $cutoff]) {
            $dias = $hoje->diffInDays($cutoff, false);
            $subset = $views->filter(fn (AtividadeProntidaoView $v) => $v->inicioPlanejado && $v->inicioPlanejado->lte($cutoff));

            $total = $subset->count();
            $concluidas = $subset->filter(fn (AtividadeProntidaoView $v) => $v->statusOperacional === StatusOperacionalProntidao::Concluida)->count();
            $prontas = $subset->filter(fn (AtividadeProntidaoView $v) => in_array($v->statusOperacional, [StatusOperacionalProntidao::Pronta, StatusOperacionalProntidao::Atencao], true))->count();
            $bloqueadas = $subset->filter(fn (AtividadeProntidaoView $v) => $v->statusOperacional === StatusOperacionalProntidao::NaoPronta)->count();
            $avaliaveis = $total - $concluidas;
            $percentual = $avaliaveis > 0 ? round(($prontas / $avaliaveis) * 100, 1) : null;

            $resultado[$chave] = new HomeHorizonte($chave, $label, (int) round($dias), $total, $concluidas, $prontas, $bloqueadas, $percentual);
        }

        return $resultado;
    }

    // =========================================================================
    // MOTOR V1 — exposição de Suprimentos por necessidade real
    // =========================================================================

    /**
     * Só chama `EstadoAtendimentoNecessidadeMaterialQuery::porAtividade()`
     * (custo fixo por atividade, já batch-safe internamente — ver
     * docblock da própria classe) para atividades que REALMENTE têm
     * `AtividadeNecessidadeMaterial` cadastrada — nunca para toda
     * atividade do horizonte. Numa obra real, o subconjunto com
     * necessidade de material cadastrada é tipicamente pequeno frente ao
     * total de atividades do horizonte (2 semanas) — o custo cresce com
     * esse subconjunto, não com o total de atividades da obra.
     *
     * @return Collection<string, Collection<int, array>> chave = atividade_id
     */
    private static function carregarMotorV1(Collection $views): Collection
    {
        $atividadeIds = $views->pluck('atividadeId');
        if ($atividadeIds->isEmpty()) {
            return collect();
        }

        $idsComNecessidade = AtividadeNecessidadeMaterial::query()
            ->whereIn('atividade_id', $atividadeIds)
            ->distinct()
            ->pluck('atividade_id');

        if ($idsComNecessidade->isEmpty()) {
            return collect();
        }

        $atividades = Atividade::whereIn('id', $idsComNecessidade)->get();

        return $atividades->mapWithKeys(
            fn (Atividade $atividade) => [$atividade->id => EstadoAtendimentoNecessidadeMaterialQuery::porAtividade($atividade)]
        );
    }

    /** Ordinal de apresentação (nunca uma regra de negócio nova) — do mais grave pro mais protegido. */
    private static function ordinalEstadoMotor(EstadoGerencialNecessidade $estado): int
    {
        return match ($estado) {
            EstadoGerencialNecessidade::DependenteFornecimentoAtrasado => 0,
            EstadoGerencialNecessidade::SemPrazo => 1,
            EstadoGerencialNecessidade::NaoContratada => 2,
            EstadoGerencialNecessidade::EmProcesso => 3,
            EstadoGerencialNecessidade::PrePedido => 4,
            EstadoGerencialNecessidade::InformacaoInsuficiente => 5,
            EstadoGerencialNecessidade::RecebidaAguardandoDisponibilizacao => 6,
            EstadoGerencialNecessidade::DependenteFornecimentoNoPrazo => 7,
            EstadoGerencialNecessidade::DisponivelNaoReservada => 8,
            EstadoGerencialNecessidade::Protegida => 9,
        };
    }

    private static function piorEstadoMotor(Collection $linhas): ?EstadoGerencialNecessidade
    {
        if ($linhas->isEmpty()) {
            return null;
        }

        return $linhas
            ->pluck('estado_gerencial')
            ->sortBy(fn (EstadoGerencialNecessidade $e) => self::ordinalEstadoMotor($e))
            ->first();
    }

    private static function suprimentosVazio(): array
    {
        return [
            'total_necessidades' => 0,
            'sem_cobertura_comercial' => 0,
            'em_processo' => 0,
            'adjudicada_sem_pedido' => 0,
            'pedido_sem_prazo' => 0,
            'entrega_posterior_necessidade' => 0,
            'dependente_no_prazo' => 0,
            'recebida_aguardando' => 0,
            'disponivel_nao_reservada' => 0,
            'protegida' => 0,
            'informacao_insuficiente' => 0,
            'frase' => 'Nenhuma necessidade de material cadastrada para as próximas 2 semanas.',
        ];
    }

    /** Tally por NECESSIDADE (Seção 15 do pedido — "8 necessidades... dependem de fornecimento"), nunca por atividade. */
    private static function montarSuprimentosExecucao(Collection $motorPorAtividade): array
    {
        $todasLinhas = $motorPorAtividade->flatMap(fn (Collection $linhas) => $linhas);

        if ($todasLinhas->isEmpty()) {
            return self::suprimentosVazio();
        }

        $contagem = self::suprimentosVazio();
        unset($contagem['frase']);
        $contagem['total_necessidades'] = $todasLinhas->count();

        foreach ($todasLinhas as $linha) {
            $chave = match ($linha['estado_gerencial']) {
                EstadoGerencialNecessidade::NaoContratada => 'sem_cobertura_comercial',
                EstadoGerencialNecessidade::EmProcesso => 'em_processo',
                EstadoGerencialNecessidade::PrePedido => 'adjudicada_sem_pedido',
                EstadoGerencialNecessidade::SemPrazo => 'pedido_sem_prazo',
                EstadoGerencialNecessidade::DependenteFornecimentoAtrasado => 'entrega_posterior_necessidade',
                EstadoGerencialNecessidade::DependenteFornecimentoNoPrazo => 'dependente_no_prazo',
                EstadoGerencialNecessidade::RecebidaAguardandoDisponibilizacao => 'recebida_aguardando',
                EstadoGerencialNecessidade::DisponivelNaoReservada => 'disponivel_nao_reservada',
                EstadoGerencialNecessidade::Protegida => 'protegida',
                EstadoGerencialNecessidade::InformacaoInsuficiente => 'informacao_insuficiente',
            };
            $contagem[$chave]++;
        }

        $exposta = $contagem['sem_cobertura_comercial'] + $contagem['pedido_sem_prazo'] + $contagem['entrega_posterior_necessidade'];
        $contagem['frase'] = $exposta > 0
            ? "{$exposta} de {$contagem['total_necessidades']} necessidade(s) de material das próximas 2 semanas ainda dependem de fornecimento sem promessa segura."
            : "Nenhuma necessidade de material das próximas 2 semanas está sem promessa de fornecimento segura.";

        return $contagem;
    }

    // =========================================================================
    // CAUSAS ("por que não estamos prontos?")
    // =========================================================================

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function montarCausas(Collection $views, Collection $motorPorAtividade): array
    {
        $naoConcluidas = $views->filter(fn (AtividadeProntidaoView $v) => $v->statusOperacional !== StatusOperacionalProntidao::Concluida);

        $buckets = [
            'engenharia' => ['label' => 'Engenharia', 'atividades' => collect()],
            'suprimentos' => ['label' => 'Suprimentos', 'atividades' => collect()],
            'planejamento' => ['label' => 'Planejamento', 'atividades' => collect()],
            'checklist' => ['label' => 'Prontidão Operacional (checklist)', 'atividades' => collect()],
            'manual' => ['label' => 'Restrições manuais', 'atividades' => collect()],
        ];

        foreach ($naoConcluidas as $view) {
            if ($view->documentosBloqueantes !== []) {
                $buckets['engenharia']['atividades']->push([
                    'id' => $view->atividadeId, 'nome' => $view->nome,
                    'causa' => 'Documento ' . ($view->documentosBloqueantes[0]->codigo ?? '') . ' não liberado para construção',
                ]);
            }

            $restricaoSuprimento = collect($view->restricoesBloqueantes)->first(fn ($r) => $r->origem === OrigemRestricaoProntidao::Suprimento);
            $motorSevero = ($piorEstado = self::piorEstadoMotor($motorPorAtividade->get($view->atividadeId, collect()))) && in_array($piorEstado, self::ESTADOS_MOTOR_RISCO, true);
            if ($restricaoSuprimento || $motorSevero) {
                $buckets['suprimentos']['atividades']->push([
                    'id' => $view->atividadeId, 'nome' => $view->nome,
                    'causa' => $restricaoSuprimento?->descricao ?? 'Exposição de fornecimento (' . $piorEstado->label() . ')',
                ]);
            }

            $restricaoPlano = collect($view->restricoesBloqueantes)->first(fn ($r) => $r->origem === OrigemRestricaoProntidao::PlanoAcao);
            if ($restricaoPlano) {
                $buckets['planejamento']['atividades']->push(['id' => $view->atividadeId, 'nome' => $view->nome, 'causa' => $restricaoPlano->descricao]);
            }

            if ($view->checklistTotal > 0 && $view->checklistPendentes !== []) {
                $buckets['checklist']['atividades']->push([
                    'id' => $view->atividadeId, 'nome' => $view->nome,
                    'causa' => count($view->checklistPendentes) . ' item(ns) de checklist pendente(s)',
                ]);
            }

            $restricaoManual = collect($view->restricoesBloqueantes)->first(fn ($r) => $r->origem === OrigemRestricaoProntidao::Manual);
            if ($restricaoManual) {
                $buckets['manual']['atividades']->push(['id' => $view->atividadeId, 'nome' => $view->nome, 'causa' => $restricaoManual->descricao]);
            }
        }

        $resultado = [];
        foreach ($buckets as $chave => $b) {
            if ($b['atividades']->isEmpty()) {
                continue;
            }
            $resultado[] = [
                'dominio' => $chave,
                'label' => $b['label'],
                'quantidade_atividades' => $b['atividades']->unique('id')->count(),
                'atividades' => $b['atividades']->unique('id')->values()->take(10)->all(),
            ];
        }

        usort($resultado, fn ($a, $b) => $b['quantidade_atividades'] <=> $a['quantidade_atividades']);

        return $resultado;
    }

    // =========================================================================
    // AMEAÇAS ("o que ameaça a execução")
    // =========================================================================

    /**
     * Ordenação (Seção 9 do pedido, documentada, nunca um score mágico):
     * 1) proximidade (dias até o início — negativo = já atrasada, sempre
     *    primeiro);
     * 2) bloqueio real (NaoPronta > Atenção > só exposição do Motor V1);
     * 3) folga do Motor V1 (mais negativa = pior, `null` fica por último);
     * 4) quantidade exposta (maior primeiro);
     * 5) id da atividade (desempate estável).
     *
     * @return array<int, array<string, mixed>>
     */
    private static function montarAmeacas(Collection $views, Collection $motorPorAtividade, Carbon $hoje): array
    {
        $candidatos = [];

        foreach ($views as $view) {
            if ($view->statusOperacional === StatusOperacionalProntidao::Concluida) {
                continue;
            }

            $linhasMotor = $motorPorAtividade->get($view->atividadeId, collect());
            $piorEstado = self::piorEstadoMotor($linhasMotor);
            $motorSevero = $piorEstado && in_array($piorEstado, self::ESTADOS_MOTOR_RISCO, true);

            $ehAmeaca = $view->statusOperacional === StatusOperacionalProntidao::NaoPronta
                || $view->statusOperacional === StatusOperacionalProntidao::Atencao
                || $motorSevero;

            if (! $ehAmeaca) {
                continue;
            }

            [$causa, $categoria] = self::descreverCausaPrincipal($view, $piorEstado);

            $severidadeRank = match (true) {
                $view->statusOperacional === StatusOperacionalProntidao::NaoPronta => 0,
                $view->statusOperacional === StatusOperacionalProntidao::Atencao => 1,
                default => 2,
            };

            $folga = $linhasMotor->pluck('folga_dias')->filter(fn ($f) => $f !== null)->sort()->first();
            $quantidadeExposta = (float) $linhasMotor->sum(fn ($l) => ($l['decomposicao_sem_cobertura'] ?? 0) + ($l['decomposicao_dependente_atrasado'] ?? 0) + ($l['decomposicao_pedida_sem_prazo'] ?? 0));

            $dias = $hoje->diffInDays($view->inicioPlanejado, false);

            $candidatos[] = [
                'atividade_id' => $view->atividadeId,
                'codigo' => $view->codigoCronograma,
                'nome' => $view->nome,
                'inicio_planejado' => $view->inicioPlanejado?->toDateString(),
                'dias_para_inicio' => (int) round($dias),
                'categoria' => $categoria,
                'severidade' => $severidadeRank === 0 ? 'bloqueante' : ($severidadeRank === 1 ? 'atencao' : 'informativo'),
                'causa' => $causa,
                'deep_link' => ['rota' => 'radar.lookahead', 'parametros' => ['atividade' => $view->atividadeId]],
                '_sort' => [(int) round($dias), $severidadeRank, $folga ?? PHP_INT_MAX, -$quantidadeExposta, $view->atividadeId],
            ];
        }

        usort($candidatos, fn ($a, $b) => $a['_sort'] <=> $b['_sort']);

        return array_slice(array_map(function ($c) {
            unset($c['_sort']);

            return $c;
        }, $candidatos), 0, self::MAX_AMEACAS);
    }

    /** @return array{0: string, 1: string} [causa, categoria] */
    private static function descreverCausaPrincipal(AtividadeProntidaoView $view, ?EstadoGerencialNecessidade $piorEstadoMotor): array
    {
        if ($view->documentosBloqueantes !== []) {
            $doc = $view->documentosBloqueantes[0];

            return ["Documento {$doc->codigo} não liberado para construção", 'engenharia'];
        }

        $restricao = collect($view->restricoesBloqueantes)->first();
        if ($restricao) {
            $categoria = match ($restricao->origem) {
                OrigemRestricaoProntidao::Suprimento => 'suprimentos',
                OrigemRestricaoProntidao::PlanoAcao => 'planejamento',
                OrigemRestricaoProntidao::Manual => 'restricao',
            };

            return [$restricao->descricao, $categoria];
        }

        if ($view->checklistTotal > 0 && $view->checklistPendentes !== []) {
            $n = count($view->checklistPendentes);

            return ["{$n} item(ns) de checklist de prontidão pendente(s)", 'checklist'];
        }

        if ($piorEstadoMotor) {
            return ['Exposição de fornecimento — ' . $piorEstadoMotor->label(), 'suprimentos'];
        }

        return ['Pendência a esclarecer', 'outro'];
    }

    // =========================================================================
    // ENGENHARIA × EXECUÇÃO
    // =========================================================================

    private static function montarEngenhariaExecucao(Collection $views): array
    {
        $documentos = [];
        foreach ($views as $view) {
            if ($view->statusOperacional === StatusOperacionalProntidao::Concluida) {
                continue;
            }
            foreach ($view->documentosBloqueantes as $doc) {
                $documentos[$doc->documentoId] ??= ['documento_id' => $doc->documentoId, 'codigo' => $doc->codigo, 'motivo' => $doc->motivo, 'atividades' => 0];
                $documentos[$doc->documentoId]['atividades']++;
            }
        }

        $totalAtividades = collect($views)->filter(fn (AtividadeProntidaoView $v) => $v->documentosBloqueantes !== [] && $v->statusOperacional !== StatusOperacionalProntidao::Concluida)->count();

        $frase = $totalAtividades > 0
            ? "{$totalAtividades} atividade(s) das próximas 2 semanas dependem de documento(s) de Engenharia ainda não liberado(s)."
            : 'Nenhuma atividade das próximas 2 semanas está bloqueada por documento de Engenharia.';

        usort($documentos, fn ($a, $b) => $b['atividades'] <=> $a['atividades']);

        return [
            'total_atividades' => $totalAtividades,
            'documentos' => array_slice(array_values($documentos), 0, 10),
            'frase' => $frase,
        ];
    }

    // =========================================================================
    // PRONTIDÃO RECUPERÁVEL
    // =========================================================================

    /**
     * Fechamento (Ciclo 25, Seções 16-23) — três grupos, nunca só
     * "1 tipo de blocker":
     *
     * A. Recuperável com 1 AÇÃO CONHECIDA — a atividade tem exatamente 1
     *    blocker simulável (restrição bloqueante específica, documento
     *    bloqueante específico, ou 1 item de checklist específico) — nunca
     *    "1 tipo" (uma atividade com 2 restrições diferentes já não é
     *    single-ação, porque teria que resolver as duas).
     * B. Recuperável com MÚLTIPLAS ações conhecidas — 2+ blockers
     *    simuláveis, todos identificados, mas resolver só 1 não libera.
     * C. Não simulável — não conta em nenhum dos dois grupos (nunca
     *    incluída no ganho potencial): hoje isso só aconteceria se uma
     *    atividade `NaoPronta` não tivesse NENHUM blocker modelável aqui
     *    (não deveria acontecer dado `estaPronta()`, mas é defensivo).
     *
     * "Ação" nunca é inventada: só existe identidade determinística pra
     * Restrição (`restricao:{id}`) e Documento (`documento:{documentoId}`)
     * — ambos podem ser COMPARTILHADOS entre atividades (resolver 1
     * Restrição vinculada a 2 atividades libera as 2 — Seção 21/22, sem
     * nenhuma otimização combinatória, só dedup por identidade). Checklist
     * é sempre por atividade (sem ID de item no DTO — `checklist:{
     * atividadeId}:{hash do nome}`), nunca compartilhado entre atividades.
     * Estoque/reserva NUNCA entra aqui — reserva não afeta hard readiness
     * (não é parte de `estaPronta()`), reafirmando a cautela já
     * documentada desde a primeira versão desta Home.
     */
    private static function montarProntidaoRecuperavel(Collection $views, HomeHorizonte $hero): array
    {
        $naoProntas = $views->filter(fn (AtividadeProntidaoView $v) => $v->statusOperacional === StatusOperacionalProntidao::NaoPronta);

        /** @var array<string, string[]> $blockersPorAtividade atividadeId => [chaves de blocker] */
        $blockersPorAtividade = [];

        foreach ($naoProntas as $v) {
            $chaves = [];

            foreach ($v->restricoesBloqueantes as $r) {
                $chaves[] = "restricao:{$r->id}";
            }
            foreach ($v->documentosBloqueantes as $d) {
                $chaves[] = "documento:{$d->documentoId}";
            }
            if ($v->checklistTotal > 0) {
                foreach ($v->checklistPendentes as $item) {
                    $chaves[] = "checklist:{$v->atividadeId}:" . md5($item);
                }
            }

            $chaves = array_values(array_unique($chaves));
            if ($chaves === []) {
                continue; // não simulável — nenhum blocker modelável identificado aqui
            }

            $blockersPorAtividade[$v->atividadeId] = $chaves;
        }

        $umaAcao = [];
        $multiplasAcoes = [];
        foreach ($blockersPorAtividade as $atividadeId => $chaves) {
            if (count($chaves) === 1) {
                $umaAcao[$atividadeId] = $chaves[0];
            } else {
                $multiplasAcoes[] = $atividadeId;
            }
        }

        // Ações compartilhadas (Seção 21/22): agrupa as atividades de
        // "uma ação" pela MESMA chave — resolver essa ação libera todas
        // as atividades daquele grupo de uma vez, sem contar a ação 2x.
        $atividadesPorAcao = [];
        foreach ($umaAcao as $atividadeId => $chave) {
            $atividadesPorAcao[$chave][] = $atividadeId;
        }

        $qtdRecuperaveisUmaAcao = count($umaAcao);
        $qtdRecuperaveisMultiplasAcoes = count($multiplasAcoes);
        $qtdRecuperaveis = $qtdRecuperaveisUmaAcao + $qtdRecuperaveisMultiplasAcoes;
        $qtdAcoesConhecidas = count($atividadesPorAcao);

        $avaliaveis = $hero->total - $hero->concluidas;
        $percentualPotencial = $avaliaveis > 0 ? round((($hero->prontas + $qtdRecuperaveis) / $avaliaveis) * 100, 1) : null;

        $frase = match (true) {
            $qtdRecuperaveis === 0 => 'A prontidão atual já representa o máximo comprovável com os fatos registrados.',
            $qtdAcoesConhecidas > 0 => "{$qtdAcoesConhecidas} ação(ões) conhecida(s) têm potencial de liberar {$qtdRecuperaveis} atividade(s)"
                . ($qtdRecuperaveisMultiplasAcoes > 0 ? " ({$qtdRecuperaveisMultiplasAcoes} delas exige(m) mais de uma ação)." : '.'),
            default => "{$qtdRecuperaveis} atividade(s) possuem bloqueios tratáveis identificados, exigindo mais de uma ação cada.",
        };

        return [
            'percentual_atual' => $hero->percentual,
            'percentual_potencial' => $percentualPotencial,
            'atividades_recuperaveis' => $qtdRecuperaveis,
            'recuperaveis_uma_acao' => $qtdRecuperaveisUmaAcao,
            'recuperaveis_multiplas_acoes' => $qtdRecuperaveisMultiplasAcoes,
            'acoes_conhecidas' => $qtdAcoesConhecidas,
            'frase' => $frase,
        ];
    }

    // =========================================================================
    // AÇÕES RECOMENDADAS
    // =========================================================================

    /**
     * Só recomenda ações cujo efeito é deterministicamente comprovável a
     * partir do dado já carregado (Seção 26 do pedido: "resolver isso
     * libera N atividades" só quando genuinamente verdadeiro) — nunca
     * inventa causalidade. Prioriza por impacto (nº de atividades
     * liberadas) e, em empate, pela atividade mais próxima.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function montarAcoesRecomendadas(Collection $views, Collection $motorPorAtividade): array
    {
        $naoProntas = $views->filter(fn (AtividadeProntidaoView $v) => $v->statusOperacional === StatusOperacionalProntidao::NaoPronta);

        // Restrição ÚNICA bloqueante (nenhum outro tipo de blocker) — resolvê-la libera a atividade com certeza.
        $porRestricaoUnica = [];
        foreach ($naoProntas as $v) {
            $temDocumento = $v->documentosBloqueantes !== [];
            $temChecklist = $v->checklistTotal > 0 && $v->checklistPendentes !== [];
            if ($temDocumento || $temChecklist || count($v->restricoesBloqueantes) !== 1) {
                continue;
            }
            $r = $v->restricoesBloqueantes[0];
            $porRestricaoUnica[$r->id] ??= ['id' => $r->id, 'descricao' => $r->descricao, 'atividades' => [], 'menor_dias' => PHP_INT_MAX];
            $porRestricaoUnica[$r->id]['atividades'][] = $v->atividadeId;
            $porRestricaoUnica[$r->id]['menor_dias'] = min($porRestricaoUnica[$r->id]['menor_dias'], $v->inicioPlanejado?->diffInDays(Carbon::today(), false) ?? PHP_INT_MAX);
        }

        $acoes = [];
        foreach ($porRestricaoUnica as $r) {
            $n = count($r['atividades']);
            $acoes[] = [
                'tipo' => 'restricao',
                'titulo' => "Tratar a restrição pendente",
                'descricao' => $r['descricao'],
                'impacto' => $n === 1 ? 'libera 1 atividade' : "libera {$n} atividades",
                'impacto_quantidade' => $n,
                'deep_link' => ['rota' => 'radar.restricoes', 'parametros' => []],
            ];
        }

        // Documento ÚNICO bloqueante — mesma lógica.
        $porDocumentoUnico = [];
        foreach ($naoProntas as $v) {
            $temRestricao = $v->restricoesBloqueantes !== [];
            $temChecklist = $v->checklistTotal > 0 && $v->checklistPendentes !== [];
            if ($temRestricao || $temChecklist || count($v->documentosBloqueantes) !== 1) {
                continue;
            }
            $d = $v->documentosBloqueantes[0];
            $porDocumentoUnico[$d->documentoId] ??= ['id' => $d->documentoId, 'codigo' => $d->codigo, 'atividades' => []];
            $porDocumentoUnico[$d->documentoId]['atividades'][] = $v->atividadeId;
        }
        foreach ($porDocumentoUnico as $d) {
            $n = count($d['atividades']);
            $acoes[] = [
                'tipo' => 'documento',
                'titulo' => "Tratar a liberação do documento {$d['codigo']}",
                'descricao' => "Documento {$d['codigo']} ainda não liberado para construção",
                'impacto' => $n === 1 ? 'libera 1 atividade' : "libera {$n} atividades",
                'impacto_quantidade' => $n,
                'deep_link' => ['rota' => 'engenharia.pacotes', 'parametros' => ['documento' => $d['id']]],
            ];
        }

        // Motor V1 — material disponível, ainda não reservado (Seção 16, exemplo literal do pedido).
        foreach ($motorPorAtividade as $atividadeId => $linhas) {
            foreach ($linhas as $linha) {
                if ($linha['estado_gerencial'] !== EstadoGerencialNecessidade::DisponivelNaoReservada) {
                    continue;
                }
                $qtd = (float) ($linha['decomposicao_disponivel'] ?? 0);
                if ($qtd <= 0.0005) {
                    continue;
                }
                $material = $linha['necessidade']->material();
                $codigoMaterial = $material?->codigo ?? 'material';
                $acoes[] = [
                    'tipo' => 'reservar',
                    'titulo' => "Reservar {$codigoMaterial}",
                    'descricao' => "{$qtd} un. de {$codigoMaterial} já disponíveis no estoque da obra, ainda sem reserva para esta atividade.",
                    'impacto' => 'protege 1 necessidade de material',
                    'impacto_quantidade' => 1,
                    'deep_link' => ['rota' => 'radar.estoque', 'parametros' => ['aba' => 'destinacao', 'material' => $material?->id]],
                ];
            }
        }

        usort($acoes, fn ($a, $b) => $b['impacto_quantidade'] <=> $a['impacto_quantidade']);

        return array_slice($acoes, 0, self::MAX_ACOES);
    }

    // =========================================================================
    // ÚLTIMOS ACONTECIMENTOS (janela fixa — ver limitação no docblock da classe)
    // =========================================================================

    private static function montarUltimosAcontecimentos(Work $obra, Carbon $hoje, Carbon $desde): array
    {
        $eventos = [];

        $restricoesResolvidas = Restricao::query()
            ->whereHas('atividade', fn ($q) => $q->where('obra_id', $obra->id))
            ->where('status', StatusRestricao::Resolvida->value)
            ->where('resolvida_em', '>=', $desde)
            ->with('atividade:id,nome')
            ->orderByDesc('resolvida_em')
            ->limit(self::MAX_ACONTECIMENTOS)
            ->get();

        foreach ($restricoesResolvidas as $r) {
            $eventos[] = [
                'tipo' => 'restricao_resolvida',
                'descricao' => "Restrição resolvida em " . ($r->atividade->nome ?? 'atividade removida') . ': ' . $r->descricao,
                'quando' => $r->resolvida_em,
                'deep_link' => ['rota' => 'radar.restricoes', 'parametros' => []],
            ];
        }

        $documentosLiberados = RevisaoLiberacao::query()
            ->where('liberada_para_construcao', true)
            ->where('created_at', '>=', $desde)
            ->whereHas('revisao.documento', fn ($q) => $q->where('obra_id', $obra->id))
            ->with('revisao.documento')
            ->orderByDesc('created_at')
            ->limit(self::MAX_ACONTECIMENTOS)
            ->get();

        foreach ($documentosLiberados as $lib) {
            $codigo = $lib->revisao?->documento?->codigo ?? '—';
            $eventos[] = [
                'tipo' => 'documento_liberado',
                'descricao' => "Documento {$codigo} liberado para construção",
                'quando' => $lib->created_at,
                'deep_link' => ['rota' => 'engenharia.pacotes', 'parametros' => ['documento' => $lib->revisao?->documento?->id]],
            ];
        }

        $recebimentos = RecebimentoPedido::query()
            ->whereHas('pedidoCompraItem.pedidoCompra', fn ($q) => $q->where('obra_id', $obra->id))
            ->where('created_at', '>=', $desde)
            ->orderByDesc('created_at')
            ->limit(self::MAX_ACONTECIMENTOS)
            ->get();

        foreach ($recebimentos as $rec) {
            $eventos[] = [
                'tipo' => 'material_recebido',
                'descricao' => "{$rec->quantidade_recebida} un. recebidas",
                'quando' => $rec->created_at,
                'deep_link' => ['rota' => 'radar.suprimentos', 'parametros' => []],
            ];
        }

        // Seção 9 do fechamento: só mostra a mudança de previsão quando dá
        // pra citar valor ANTERIOR + NOVO + quando — nunca "Pedido
        // atualizado" genérico. `PedidoCompraPrevisaoEntrega` é append-only
        // (Ciclo 19, Etapa Suprimentos) — cada linha é uma revisão; a
        // ANTERIOR à revisão ocorrida na janela é a imediatamente anterior
        // por ordem de registro (nunca por `data_prevista`, que é o dado
        // revisado em si).
        $revisoesPrevisao = PedidoCompraPrevisaoEntrega::query()
            ->whereHas('pedidoCompra', fn ($q) => $q->where('obra_id', $obra->id))
            ->where('registrado_em', '>=', $desde)
            ->with('pedidoCompra:id,numero')
            ->orderByDesc('registrado_em')
            ->limit(self::MAX_ACONTECIMENTOS)
            ->get();

        foreach ($revisoesPrevisao as $revisao) {
            $anterior = PedidoCompraPrevisaoEntrega::query()
                ->where('pedido_compra_id', $revisao->pedido_compra_id)
                ->where(function ($q) use ($revisao) {
                    $q->where('registrado_em', '<', $revisao->registrado_em)
                        ->orWhere(function ($q2) use ($revisao) {
                            $q2->where('registrado_em', $revisao->registrado_em)->where('id', '<', $revisao->id);
                        });
                })
                ->orderByDesc('registrado_em')
                ->orderByDesc('id')
                ->first();

            if (! $anterior || $anterior->data_prevista?->toDateString() === $revisao->data_prevista?->toDateString()) {
                continue; // sem valor anterior distinto pra comparar — nunca inventa "mudou de X pra X"
            }

            $numero = $revisao->pedidoCompra?->numero ?? '—';
            $eventos[] = [
                'tipo' => 'pedido_previsao_revisada',
                'descricao' => "Pedido PC-{$numero} teve a previsão de entrega alterada de "
                    . $anterior->data_prevista->format('d/m') . ' para ' . $revisao->data_prevista->format('d/m'),
                'quando' => $revisao->registrado_em,
                'deep_link' => ['rota' => 'radar.suprimentos', 'parametros' => []],
            ];
        }

        usort($eventos, fn ($a, $b) => $b['quando']->timestamp <=> $a['quando']->timestamp);

        return array_slice(array_map(fn ($e) => $e + ['quando' => $e['quando']->toIso8601String()], $eventos), 0, self::MAX_ACONTECIMENTOS);
    }

    // =========================================================================
    // EXECUÇÃO DA SEMANA / PPC (Ciclo 24, mesma fórmula corrigida)
    // =========================================================================

    private static function montarExecucaoSemana(Work $obra, Carbon $hoje): ?array
    {
        $semana = DB::table('programacoes_semanais as ps')
            ->where('ps.obra_id', $obra->id)
            ->where('ps.semana_fim', '<', $hoje->copy()->startOfWeek()->toDateString())
            ->whereRaw(
                'ps.versao = (SELECT MAX(ps2.versao) FROM programacoes_semanais ps2 '
                . 'WHERE ps2.obra_id = ps.obra_id AND ps2.semana_inicio = ps.semana_inicio)'
            )
            ->orderByDesc('ps.semana_inicio')
            ->first();

        if (! $semana) {
            return null;
        }

        $itens = ProgramacaoSemanalItem::where('programacao_semanal_id', $semana->id)
            ->with('atividade:id,nome,concluido_em')
            ->get();

        $comprometidas = $itens->count();
        if ($comprometidas === 0) {
            return null;
        }

        $semanaFim = Carbon::parse($semana->semana_fim);
        $semanaInicio = Carbon::parse($semana->semana_inicio);

        $concluidasNoPrazo = $itens->filter(
            fn (ProgramacaoSemanalItem $i) => $i->atividade?->concluido_em && Carbon::parse($i->atividade->concluido_em)->lte($semanaFim)
        );
        $naoConcluidas = $itens->reject(fn (ProgramacaoSemanalItem $i) => $concluidasNoPrazo->contains('id', $i->id));

        $ppc = round(($concluidasNoPrazo->count() / $comprometidas) * 100, 1);

        $atividadeIdsNaoConcluidas = $naoConcluidas->pluck('atividade_id')->filter();

        $causasPorAtividade = CausaNaoCumprimento::whereIn('atividade_id', $atividadeIdsNaoConcluidas)
            ->orderByDesc('created_at')
            ->get()
            ->unique('atividade_id')
            ->keyBy('atividade_id');

        // Seção 11 do fechamento: uma Restrição só é sinal de risco "conhecido
        // antes da semana" se, NO INSTANTE semana_inicio, ela já existia E
        // ainda não tinha sido resolvida — nunca uma que abriu e já fechou
        // antes da semana começar (nunca foi risco DURANTE a semana).
        $restricoesAntesDaSemana = Restricao::whereIn('atividade_id', $atividadeIdsNaoConcluidas)
            ->where('bloqueante', true)
            ->where('aberta_em', '<=', $semanaInicio)
            ->where(function ($q) use ($semanaInicio) {
                $q->whereNull('resolvida_em')->orWhere('resolvida_em', '>', $semanaInicio);
            })
            ->pluck('atividade_id')
            ->unique();

        // Seção 12: Engenharia como sinal histórico — só reconstruível com
        // rigor quando a revisão vigente HOJE já existia antes do cutoff
        // (nenhuma revisão nova chegou depois, então vigência-então ===
        // vigência-agora); caso contrário, não inventamos.
        $documentoConhecidoAntes = self::engenhariaConhecidaAntes($atividadeIdsNaoConcluidas, $semanaInicio);

        $causas = ['restricao' => 0, 'engenharia' => 0, 'causa_registrada' => 0, 'sem_causa' => 0];
        $sistemaJaSabia = 0;
        foreach ($naoConcluidas as $item) {
            $temRestricaoPreExistente = $item->atividade_id && $restricoesAntesDaSemana->contains($item->atividade_id);
            $temDocumentoPreExistente = $item->atividade_id && $documentoConhecidoAntes->contains($item->atividade_id);

            // Uma atividade com múltiplos sinais conta UMA vez no
            // numerador total (Seção 14) — as subcategorias podem sobrepor.
            if ($temRestricaoPreExistente || $temDocumentoPreExistente) {
                $sistemaJaSabia++;
            }

            if ($temRestricaoPreExistente) {
                $causas['restricao']++;
            }
            if ($temDocumentoPreExistente) {
                $causas['engenharia']++;
            }
            if (! $temRestricaoPreExistente && ! $temDocumentoPreExistente) {
                if ($causasPorAtividade->has($item->atividade_id)) {
                    $causas['causa_registrada']++;
                } else {
                    $causas['sem_causa']++;
                }
            }
        }

        return [
            'semana_inicio' => $semanaInicio->toDateString(),
            'semana_fim' => $semanaFim->toDateString(),
            'comprometidas' => $comprometidas,
            'concluidas_no_prazo' => $concluidasNoPrazo->count(),
            'nao_concluidas' => $naoConcluidas->count(),
            'ppc_percentual' => $ppc,
            'causas_nao_conclusao' => $causas,
            'sistema_ja_sabia' => $sistemaJaSabia,
            // Seção 15: linguagem sempre cuidadosa — "já apresentavam
            // sinais de risco", NUNCA "não concluíram por causa desses
            // riscos" (não existe causalidade registrada pra afirmar isso).
            'frase' => $naoConcluidas->isEmpty()
                ? 'Todas as atividades comprometidas na última semana fechada foram concluídas no prazo.'
                : ($sistemaJaSabia > 0
                    ? "{$sistemaJaSabia} de {$naoConcluidas->count()} não conclusão(ões) desta semana já apresentavam sinais de risco antes do início da semana."
                    : "{$naoConcluidas->count()} atividade(s) não foram concluídas no prazo na última semana fechada."),
        ];
    }

    /**
     * Seção 12/13 do fechamento — sinal histórico de Engenharia,
     * reconstruído com rigor (nunca inventado): uma atividade "já sabia"
     * do bloqueio documental antes da semana quando (1) o documento
     * vinculado a ela tem uma revisão vigente HOJE que já existia
     * (`created_at`) antes de `semanaInicio` — garantindo que a vigência
     * não mudou desde então, senão não temos como saber com segurança
     * qual revisão era vigente naquele instante — e (2) o histórico de
     * liberação daquela revisão, olhando só eventos ATÉ `semanaInicio`,
     * mostra que ela ainda não estava liberada para construção (nenhum
     * evento, ou o último evento até ali é `liberada_para_construcao =
     * false`). Documentos com revisão nova criada DEPOIS do cutoff são
     * silenciosamente ignorados — não reconstruímos vigência histórica
     * sem essa garantia (Suprimentos, pelo mesmo motivo mais agravado
     * — não é possível reconstruir com rigor a previsão vigente num
     * cutoff passado a partir do estado atual do Motor V1 — permanece
     * NÃO IMPLEMENTADO nesta etapa).
     *
     * @param  \Illuminate\Support\Collection<int, string>  $atividadeIds
     * @return \Illuminate\Support\Collection<int, string> ids de atividade
     */
    private static function engenhariaConhecidaAntes(Collection $atividadeIds, Carbon $semanaInicio): Collection
    {
        if ($atividadeIds->isEmpty()) {
            return collect();
        }

        $atividades = Atividade::with([
            'documentosEngenharia.latestRevisao.historicoLiberacoes',
        ])->whereIn('id', $atividadeIds)->get();

        $resultado = collect();
        foreach ($atividades as $atividade) {
            foreach ($atividade->documentosEngenharia as $doc) {
                $revisaoVigente = $doc->latestRevisao;
                if (! $revisaoVigente || ! $revisaoVigente->created_at || $revisaoVigente->created_at->gt($semanaInicio)) {
                    // Não reconstruível com segurança — pula, nunca inventa.
                    continue;
                }

                $ultimoEventoAteCutoff = $revisaoVigente->historicoLiberacoes
                    ->filter(fn ($e) => $e->created_at !== null && $e->created_at->lte($semanaInicio))
                    ->sortBy([['created_at', 'asc'], ['id', 'asc']])
                    ->last();

                $liberadaAntes = (bool) $ultimoEventoAteCutoff?->liberada_para_construcao;

                if (! $liberadaAntes) {
                    $resultado->push($atividade->id);
                    break; // 1 documento não liberado já basta pra esta atividade
                }
            }
        }

        return $resultado->unique();
    }

    // =========================================================================
    // FRASE GERENCIAL
    // =========================================================================

    private static function montarFraseGerencial(HomeHorizonte $hero, array $causas): string
    {
        if ($hero->total === 0) {
            return 'Nenhuma atividade prevista para as próximas 2 semanas.';
        }

        if ($hero->bloqueadas === 0) {
            return 'Nenhuma atividade crítica das próximas 2 semanas possui bloqueio aberto.';
        }

        if ($causas === []) {
            return "{$hero->bloqueadas} atividade(s) das próximas 2 semanas estão bloqueadas.";
        }

        $dominante = $causas[0];
        $n = $dominante['quantidade_atividades'];
        $label = $dominante['label'];

        return "O principal risco está em {$label}: {$n} atividade(s) das próximas 2 semanas são afetadas.";
    }
}
