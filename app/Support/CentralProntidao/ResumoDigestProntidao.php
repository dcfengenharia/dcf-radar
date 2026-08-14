<?php

namespace App\Support\CentralProntidao;

use Carbon\Carbon;

/**
 * Resultado consolidado do núcleo de detecção do Digest de Prontidão
 * (Ciclo 16, Etapa A.2) — puramente estrutura de dados, sem nenhuma
 * lógica de consulta ao banco (isso vive só em `App\Services\
 * DigestProntidao`). Mesmo espírito de `AtividadeProntidaoView`: nunca
 * decide nada por conta própria, só carrega o que `CentralProntidaoQuery`
 * já calculou — inclusive `$atividadesProblematicas`, que são as MESMAS
 * instâncias de `AtividadeProntidaoView` retornadas por `paraObra()`,
 * nunca uma releitura.
 *
 * Carrega dado suficiente pra uma futura Notification (Etapa A.3) montar
 * a mensagem do digest sem precisar chamar `CentralProntidaoQuery`/
 * `DigestProntidao` de novo (Ciclo 16, A.1, seção 6).
 *
 * @param  AtividadeProntidaoView[]  $atividadesProblematicas  só NAO_PRONTA/ATENCAO, dentro do horizonte
 */
final readonly class ResumoDigestProntidao
{
    public function __construct(
        public string $obraId,
        public string $obraNome,
        public int $horizonteDias,
        public Carbon $horizonteAte,
        public Carbon $geradoEm,
        public int $totalAtividadesUniverso,
        public int $totalNaoPronta,
        public int $totalAtencao,
        public int $totalExigeAtencao,
        public bool $temPendencias,
        public array $atividadesProblematicas,
    ) {
    }
}
