<?php

namespace App\Enums;

enum PilarLean: string
{
    case Materiais = 'materiais';
    case MaoDeObra = 'mao_de_obra';
    case Equipamentos = 'equipamentos';
    case Informacoes = 'informacoes';
    case CondicoesPrecedentes = 'condicoes_precedentes';
}
