<?php

namespace App\Support;

class TemplateConvite
{
    public static function substituir(string $texto, string $obra, string $convidadoPor, string $papel): string
    {
        return strtr($texto, [
            '{{obra}}' => $obra,
            '{{convidado_por}}' => $convidadoPor,
            '{{papel}}' => $papel,
        ]);
    }

    public static function assuntoPadrao(): string
    {
        return 'Você foi convidado para a obra {{obra}}';
    }

    public static function mensagemPadrao(): string
    {
        return 'Você foi convidado por {{convidado_por}} para participar da obra {{obra}}, '
            . 'com o papel de {{papel}}. Clique no botão abaixo para criar sua senha e começar.';
    }
}
