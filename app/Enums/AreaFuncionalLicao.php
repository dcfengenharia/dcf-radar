<?php

namespace App\Enums;

/**
 * Ciclo 23, Etapa 23.1 — grandes áreas funcionais do DCF.ENG, pra
 * classificar a lição. Investigado antes de criar: não existe nenhuma
 * taxonomia equivalente já pronta (Disciplina é outra coisa — mais
 * granular, técnica, reaproveitada à parte via `disciplina_id`).
 */
enum AreaFuncionalLicao: string
{
    case Planejamento = 'planejamento';
    case Engenharia = 'engenharia';
    case Suprimentos = 'suprimentos';
    case Estoque = 'estoque';
    case Industrializacao = 'industrializacao';
    case Campo = 'campo';
    case Qualidade = 'qualidade';
    case Gestao = 'gestao';
    case Seguranca = 'seguranca';
    case Outra = 'outra';

    public function label(): string
    {
        return match ($this) {
            self::Planejamento => 'Planejamento',
            self::Engenharia => 'Engenharia',
            self::Suprimentos => 'Suprimentos',
            self::Estoque => 'Estoque',
            self::Industrializacao => 'Industrialização',
            self::Campo => 'Campo',
            self::Qualidade => 'Qualidade',
            self::Gestao => 'Gestão',
            self::Seguranca => 'Segurança',
            self::Outra => 'Outra',
        };
    }
}
