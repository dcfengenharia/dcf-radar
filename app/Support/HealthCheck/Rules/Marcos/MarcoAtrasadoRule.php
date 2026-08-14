<?php

namespace App\Support\HealthCheck\Rules\Marcos;

use App\DTOs\PlanoImportacao;
use App\DTOs\TarefaImportada;
use App\Enums\HealthCheckCategoria;
use App\Enums\HealthCheckNaturezaRegra;
use App\Enums\HealthCheckSeveridade;
use App\Support\HealthCheck\RegraHealthCheckBase;

class MarcoAtrasadoRule extends RegraHealthCheckBase
{
    public function id(): string
    {
        return 'MILE-001';
    }

    public function natureza(): HealthCheckNaturezaRegra
    {
        return HealthCheckNaturezaRegra::Execucao;
    }

    public function categoria(): HealthCheckCategoria
    {
        return HealthCheckCategoria::Marcos;
    }

    public function severidade(): HealthCheckSeveridade
    {
        return HealthCheckSeveridade::Alto;
    }

    public function titulo(): string
    {
        return 'Marco atrasado';
    }

    public function descricao(int $quantidade): string
    {
        return "Foram encontrados {$quantidade} marco(s) cuja data planejada já passou da data de status sem estarem concluídos.";
    }

    public function impacto(): string
    {
        return 'Marcos representam compromissos-chave do projeto (entregas, aprovações, liberações) — um marco atrasado costuma ter visibilidade direta com o cliente.';
    }

    public function recomendacao(): string
    {
        return 'Revisar a data prevista desse marco e comunicar o novo prazo às partes interessadas, se necessário.';
    }

    protected function combina(TarefaImportada $tarefa, PlanoImportacao $plano): bool
    {
        return $tarefa->isMarco
            && $tarefa->realTermino === null
            && $tarefa->dataTermino !== null
            && $plano->dataStatus !== null
            && $tarefa->dataTermino->lt($plano->dataStatus);
    }
}
