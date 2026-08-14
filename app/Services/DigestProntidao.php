<?php

namespace App\Services;

use App\Models\Work;
use App\Support\CentralProntidao\CentralProntidaoQuery;
use App\Support\CentralProntidao\ResumoDigestProntidao;
use App\Support\CentralProntidao\StatusOperacionalProntidao;
use Carbon\Carbon;

/**
 * Núcleo de detecção/consolidação do Digest de Prontidão (Ciclo 16,
 * Etapa A.2) — responde, pra UMA obra, "existe algo que merece entrar no
 * Digest e quais são os números?". Consome integralmente
 * `CentralProntidaoQuery::paraObra()`, nunca recria regra de prontidão
 * nem reconsulta `Atividade::scopeProntas()`/`estaPronta()` (Ciclo 14,
 * princípio 2, já seguido por toda a Central de Prontidão).
 *
 * Deliberadamente NÃO é um Scheduler: não itera tenants/obras, não
 * resolve destinatários, não envia nada, não decide cadência — isso é
 * responsabilidade da Etapa A.4 (Command/Scheduler), ainda não
 * implementada. Esta classe só calcula/consolida (Ciclo 16, A.2, seção 7).
 *
 * Horizonte fixo de 30 dias nesta primeira versão (Ciclo 16, A.2, seção
 * 2) — mesma semântica já usada por `⚡central-prontidao.blade.php`
 * (`now()->addDays($dias)`, passado como `$horizonteAte` pra
 * `CentralProntidaoQuery::paraObra()`, que filtra
 * `inicio_planejado <= $horizonteAte` sem limite inferior — atividades
 * atrasadas continuam entrando). Nenhuma configuração nova (nem por obra,
 * nem por tenant) foi criada pra isso.
 */
class DigestProntidao
{
    public const HORIZONTE_DIAS = 30;

    public function __construct(
        private readonly CentralProntidaoQuery $query,
    ) {
    }

    /**
     * Recebe UMA obra explicitamente — nunca itera tenants/obras (Ciclo
     * 16, A.2, seção 9). O isolamento tenant/obra do resultado vem
     * inteiramente de `CentralProntidaoQuery::paraObra()`, nunca
     * reimplementado aqui.
     */
    public function consolidar(Work $obra): ResumoDigestProntidao
    {
        $horizonteAte = Carbon::now()->addDays(self::HORIZONTE_DIAS);

        $views = $this->query->paraObra($obra, $horizonteAte);

        $problematicas = $views
            ->filter(fn ($view) => in_array($view->statusOperacional, [
                StatusOperacionalProntidao::NaoPronta,
                StatusOperacionalProntidao::Atencao,
            ], true))
            ->values();

        $totalNaoPronta = $problematicas
            ->filter(fn ($view) => $view->statusOperacional === StatusOperacionalProntidao::NaoPronta)
            ->count();

        $totalAtencao = $problematicas
            ->filter(fn ($view) => $view->statusOperacional === StatusOperacionalProntidao::Atencao)
            ->count();

        $totalExigeAtencao = $totalNaoPronta + $totalAtencao;

        return new ResumoDigestProntidao(
            obraId: $obra->id,
            obraNome: $obra->name,
            horizonteDias: self::HORIZONTE_DIAS,
            horizonteAte: $horizonteAte,
            geradoEm: Carbon::now(),
            totalAtividadesUniverso: $views->count(),
            totalNaoPronta: $totalNaoPronta,
            totalAtencao: $totalAtencao,
            totalExigeAtencao: $totalExigeAtencao,
            temPendencias: $totalExigeAtencao > 0,
            atividadesProblematicas: $problematicas->all(),
        );
    }
}
