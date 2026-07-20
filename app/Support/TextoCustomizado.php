<?php

namespace App\Support;

class TextoCustomizado
{
    /**
     * Lê um campo de texto customizado (Texto20..Texto30) do array de
     * `textos` de uma Atividade/tarefa importada, aceitando tanto a
     * grafia em português ("Texto21") quanto em inglês ("Text21") —
     * depende do idioma da instalação do MS Project que gerou o XML.
     */
    public static function valor(array $textos, int $numero): string
    {
        return trim($textos["Texto{$numero}"] ?? $textos["Text{$numero}"] ?? '');
    }
}
