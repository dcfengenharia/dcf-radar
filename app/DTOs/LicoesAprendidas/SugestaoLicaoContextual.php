<?php

namespace App\DTOs\LicoesAprendidas;

use App\Enums\AreaFuncionalLicao;
use App\Enums\CriticidadeLicao;
use App\Enums\MotivoCorrespondenciaLicao;
use App\Enums\TipoLicaoAprendida;
use Carbon\CarbonInterface;

/**
 * Ciclo 23, Etapa 23.4 — resultado de `LicoesContextuaisQuery`. Só dado,
 * `readonly`, nunca persistido (Seção 22 do pedido — 100% derivado sob
 * demanda). Carrega exatamente o recorte já aprovado como seguro pelo
 * precedente da própria biblioteca corporativa (`⚡licoes-aprendidas
 * .blade.php`, detalhe de uma lição Publicada) — NUNCA
 * `observacoes_internas`, NUNCA a entidade operacional original da obra
 * de origem, NUNCA um deep-link operacional (Seção 23).
 */
final readonly class SugestaoLicaoContextual
{
    /**
     * @param  array<int, array{motivo: MotivoCorrespondenciaLicao, contexto: ?string}>  $motivos
     */
    public function __construct(
        public string $licaoId,
        public string $titulo,
        public string $situacaoObservada,
        public string $recomendacaoFutura,
        public TipoLicaoAprendida $tipo,
        public CriticidadeLicao $criticidade,
        public AreaFuncionalLicao $areaFuncional,
        public ?string $disciplinaNome,
        public ?string $obraOrigemNome,
        public ?CarbonInterface $publicadoEm,
        public array $motivos,
    ) {
    }

    /** Maior motivo de precedência entre os que bateram — nunca "maior risco" (Seção 18). */
    public function especificidade(): int
    {
        return collect($this->motivos)->max(fn (array $m) => $m['motivo']->precedencia()) ?? 0;
    }

    /**
     * Tupla de ordenação determinística (Seção 34): especificidade desc
     * → criticidade da própria lição desc → publicação mais recente
     * desc → id como desempate estável — nunca um score único.
     *
     * @return array{0: int, 1: int, 2: int, 3: string}
     */
    public function chaveOrdenacao(): array
    {
        return [
            -$this->especificidade(),
            -$this->criticidade->peso(),
            -($this->publicadoEm?->timestamp ?? 0),
            $this->licaoId,
        ];
    }
}
