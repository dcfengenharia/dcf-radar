<?php

namespace App\Enums;

enum HealthCheckCategoria: string
{
    case Datas = 'datas';
    case Avanco = 'avanco';
    case Hh = 'hh';
    case Duracao = 'duracao';
    case CaminhoCritico = 'caminho_critico';
    case Marcos = 'marcos';
    case Baseline = 'baseline';
    case Estrutura = 'estrutura';
    case Logica = 'logica';
    case Slack = 'slack';

    public function label(): string
    {
        return match ($this) {
            self::Datas => 'Datas',
            self::Avanco => 'Avanço Físico',
            self::Hh => 'HH',
            self::Duracao => 'Duração',
            self::CaminhoCritico => 'Caminho Crítico',
            self::Marcos => 'Marcos',
            self::Baseline => 'Baseline',
            self::Estrutura => 'Estrutura',
            self::Logica => 'Lógica',
            self::Slack => 'Folgas',
        };
    }
}
