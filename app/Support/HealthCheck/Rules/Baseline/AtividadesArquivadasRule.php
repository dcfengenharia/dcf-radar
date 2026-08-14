<?php

namespace App\Support\HealthCheck\Rules\Baseline;

use App\DTOs\PlanoImportacao;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\HealthCheckSeveridade;
use App\Support\HealthCheck\HealthCheckFinding;
use App\Support\HealthCheck\HealthCheckRuleInterface;

/**
 * Regra que reaproveita $plano->removerNomes (já calculado por
 * MsProjectImporter::analisar()) — as atividades removidas do XML não são
 * TarefaImportada (vêm do banco, já casadas por external_uid), por isso
 * implementa a interface direto em vez de estender RegraHealthCheckBase.
 */
class AtividadesArquivadasRule implements HealthCheckRuleInterface
{
    public function id(): string
    {
        return 'BASE-002';
    }

    public function natureza(): HealthCheckNaturezaRegra
    {
        return HealthCheckNaturezaRegra::Planejamento;
    }

    public function categoria(): HealthCheckCategoria
    {
        return HealthCheckCategoria::Baseline;
    }

    public function severidade(): HealthCheckSeveridade
    {
        return HealthCheckSeveridade::Baixo;
    }

    public function bloqueante(): bool
    {
        return false;
    }

    public function avaliar(PlanoImportacao $plano): ?HealthCheckFinding
    {
        if (empty($plano->removerNomes)) {
            return null;
        }

        return new HealthCheckFinding(
            regraId: $this->id(),
            categoria: $this->categoria(),
            severidade: $this->severidade(),
            titulo: 'Atividades que sairão do cronograma',
            descricao: count($plano->removerNomes) . ' atividade(s) presente(s) no cronograma atual não aparecem mais neste arquivo e serão arquivadas.',
            impacto: 'Atividades arquivadas nunca são apagadas (restrições vinculadas são preservadas), mas deixam de aparecer no Lookahead e no Plano Semanal como ativas.',
            recomendacao: 'Confirmar se essas atividades realmente saíram do escopo antes de prosseguir com a importação.',
            atividades: array_map(fn (string $nome) => ['nome' => $nome], $plano->removerNomes),
        );
    }
}
