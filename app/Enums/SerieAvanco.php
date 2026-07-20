<?php

namespace App\Enums;

enum SerieAvanco: string
{
    case Previsto  = 'previsto';
    case Realizado = 'realizado';
    case Tendencia = 'tendencia';
}
