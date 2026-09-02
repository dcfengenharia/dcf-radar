<?php

namespace App\DTOs\Gestao;

use App\Enums\SeveridadeSituacao;
use App\Enums\TipoSituacaoGerencial;
use Carbon\CarbonInterface;

/**
 * Ciclo 21, Etapa 21.2 — substitui `App\DTOs\Gestao\AcaoGerencial` (21.1,
 * removida) como a ÚNICA representação de "situação gerencial que exige
 * atenção" (Seção 17 do pedido — "não duplicar risco"). Mesma filosofia:
 * `readonly`, `final`, **NUNCA persistido** — só uma tabela de
 * COMUNICAÇÃO (não da situação em si) é cogitada pra 21.3, ver proposta
 * no relatório final desta etapa.
 *
 * `tipo`/`severidade` agora são enums fechados (`TipoSituacaoGerencial`/
 * `SeveridadeSituacao`), nunca strings soltas — a 21.1 usava `string`
 * porque o catálogo ainda não existia.
 */
final class SituacaoGerencial
{
    /**
     * @param  array<int, array{slug: string, acao: string}>  $destinatariosPerfis
     *   perfis/permissões conceituais que deveriam se importar — NUNCA
     *   um usuário específico inventado (Seção 10). Resolvido em
     *   usuários reais só por
     *   `App\Support\Gestao\SituacoesGerenciaisQuery::resolverDestinatarios()`,
     *   sempre obra-escopado.
     * @param  array{rota: string, parametros: array<string, mixed>}  $deepLink
     *   representação ESTRUTURADA (nome de rota nomeada + parâmetros),
     *   nunca uma URL montada à mão dentro do domínio (Seção 12).
     * @param  array<string, mixed>  $contexto
     *   dados suficientes pra explicar "por que isto está aparecendo"
     *   (Seção 22) — condição avaliada, valor atual, limite/regra,
     *   entidade de origem. Nunca só pra exibição — é a fonte da
     *   explicabilidade.
     */
    public function __construct(
        public readonly TipoSituacaoGerencial $tipo,
        public readonly SeveridadeSituacao $severidade,
        public readonly string $obraId,
        public readonly string $entidadeTipo,
        public readonly string $entidadeId,
        public readonly string $chaveLogica,
        public readonly string $descricao,
        public readonly ?string $motivo = null,
        public readonly ?float $quantidade = null,
        public readonly ?CarbonInterface $dataRelevante = null,
        public readonly ?int $diasParaRelevante = null,
        public readonly int $impactoOperacional = 0,
        public readonly ?int $diasAtraso = null,
        public readonly array $destinatariosPerfis = [],
        public readonly array $deepLink = [],
        public readonly array $contexto = [],
    ) {
    }

    /**
     * Chave de ordenação (Seção 7) — tupla explícita, NUNCA um score
     * opaco. Ordem de prioridade, na ordem exata pedida: (1) impacto
     * operacional (maior primeiro); (2) proximidade temporal (menor
     * `diasParaRelevante` primeiro — `null`/sem prazo vai pro fim, nunca
     * primeiro por omissão); (3) severidade (maior primeiro); (4)
     * atraso (maior primeiro); (5) `chaveLogica` — desempate ESTÁVEL,
     * nunca dependente da ordem de retorno do banco.
     *
     * @return array{0: int, 1: int, 2: int, 3: int, 4: string}
     */
    public function chaveOrdenacao(): array
    {
        return [
            -$this->impactoOperacional,
            $this->diasParaRelevante ?? PHP_INT_MAX,
            -$this->severidade->peso(),
            -($this->diasAtraso ?? 0),
            $this->chaveLogica,
        ];
    }

    public function toArray(): array
    {
        return [
            'tipo' => $this->tipo->value,
            'severidade' => $this->severidade->value,
            'obra_id' => $this->obraId,
            'entidade_tipo' => $this->entidadeTipo,
            'entidade_id' => $this->entidadeId,
            'chave_logica' => $this->chaveLogica,
            'descricao' => $this->descricao,
            'motivo' => $this->motivo,
            'quantidade' => $this->quantidade,
            'data_relevante' => $this->dataRelevante?->toDateString(),
            'dias_para_relevante' => $this->diasParaRelevante,
            'impacto_operacional' => $this->impactoOperacional,
            'dias_atraso' => $this->diasAtraso,
            'destinatarios_perfis' => $this->destinatariosPerfis,
            'deep_link' => $this->deepLink,
            'contexto' => $this->contexto,
        ];
    }
}
