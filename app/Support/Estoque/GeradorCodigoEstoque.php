<?php

namespace App\Support\Estoque;

use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\UnidadeEstoque;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;

/**
 * Ciclo 20, Etapa 20.8 — gera o código textual (`MAT:{ulid}` etc.) e o
 * SVG do QR correspondente. Mesma biblioteca/técnica já usada por
 * `App\Support\Grd\MontarDadosComprovanteEntrega::gerarQrSvg()` (Ciclo
 * 18, 18.5.9) — `bacon/bacon-qr-code`, já instalada transitivamente via
 * `laravel/fortify`, ZERO dependência nova.
 *
 * **Decisão do usuário (STOP-and-ask, Seção 5)**: só QR Code nesta etapa
 * — nenhuma biblioteca de barcode 1D foi instalada (nenhuma existia no
 * projeto). Código 1D fica pra uma etapa futura, se houver necessidade
 * real confirmada.
 *
 * O código nunca muda depois de gerado (Seção 25) — é sempre derivado
 * do ULID da entidade, que é a PK e nunca é reescrita.
 */
class GeradorCodigoEstoque
{
    public static function codigoMaterial(Material $material): string
    {
        return "MAT:{$material->id}";
    }

    public static function codigoUnidade(UnidadeEstoque $unidade): string
    {
        return "UNI:{$unidade->id}";
    }

    public static function codigoLocal(LocalEstoque $local): string
    {
        return "LOC:{$local->id}";
    }

    public static function qrSvg(string $codigo, int $tamanhoPx = 160): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle($tamanhoPx, 0, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(0, 0, 0))),
            new SvgImageBackEnd()
        );

        return (new Writer($renderer))->writeString($codigo);
    }
}
