<?php

namespace App\Enums;

/**
 * FASE 2D, Seção 28 — de ONDE (qual fluxo/tela) a mudança de acesso
 * partiu. Deliberadamente semântico, nunca acoplado a nome de rota/
 * componente Blade (uma futura tela "⚡perfis-acesso-v2.blade.php"
 * continua gravando `PerfilAcesso`, sem precisar de migração de dado).
 */
enum OrigemEventoHistoricoAcesso: string
{
    case PerfilAcesso = 'perfil_acesso';
    case MatrizAcessos = 'matriz_acessos';
    case EquipeObra = 'equipe_obra';
    case Convite = 'convite';
    case Sistema = 'sistema';

    public function label(): string
    {
        return match ($this) {
            self::PerfilAcesso => 'Perfis de Acesso',
            self::MatrizAcessos => 'Matriz de Acessos',
            self::EquipeObra => 'Equipe da Obra',
            self::Convite => 'Convite',
            self::Sistema => 'Sistema',
        };
    }
}
