<?php

namespace App\Enums;

enum TipoAvisoPlataforma: string
{
    case Informativo = 'informativo';
    case Aviso       = 'aviso';
    case Urgente     = 'urgente';
}
