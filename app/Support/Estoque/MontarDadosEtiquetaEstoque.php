<?php

namespace App\Support\Estoque;

use App\Models\LocalEstoque;
use App\Models\Material;
use App\Models\UnidadeEstoque;
use Illuminate\Support\Collection;

/**
 * Ciclo 20, Etapa 20.8 — monta o VIEW-MODEL de etiqueta (Material/
 * UnidadeEstoque/LocalEstoque), pronto pra renderizar em qualquer
 * template de tamanho (pequena/média/A4-listagem, todos no MESMO Blade
 * de export). Puramente de LEITURA — nenhuma regra de domínio, mesmo
 * espírito de `MontarDadosPdfGrd`/`MontarDadosComprovanteEntrega`.
 *
 * **Princípio central (Seção 10/1 do pedido) — NUNCA imprime**:
 * - saldo/quantidade (muda a todo momento);
 * - Local atual de uma UnidadeEstoque (`local_estoque_id` é só origem/
 *   criação desde a 20.5.CORREÇÃO — uma bobina pode estar fracionada em
 *   vários Locais ao mesmo tempo, imprimir "está no Local X" seria
 *   factualmente errado assim que uma Transferência parcial acontecer).
 *
 * Conteúdo humano mínimo: código do sistema (legível), descrição,
 * lote/serial quando aplicável, e o QR.
 */
class MontarDadosEtiquetaEstoque
{
    /**
     * @param  Collection<int, Material|UnidadeEstoque|LocalEstoque>  $entidades
     * @return array<int, array{tipo: string, codigo_texto: string, titulo: string, subtitulo: ?string, qr_svg: string}>
     */
    public static function paraEntidades(Collection $entidades): array
    {
        return $entidades->map(fn ($entidade) => self::paraUmaEntidade($entidade))->all();
    }

    public static function paraUmaEntidade(Material|UnidadeEstoque|LocalEstoque $entidade): array
    {
        return match (true) {
            $entidade instanceof Material => [
                'tipo' => 'material',
                'codigo_texto' => GeradorCodigoEstoque::codigoMaterial($entidade),
                'titulo' => $entidade->codigo,
                'subtitulo' => $entidade->descricao,
                'qr_svg' => GeradorCodigoEstoque::qrSvg(GeradorCodigoEstoque::codigoMaterial($entidade)),
            ],
            $entidade instanceof UnidadeEstoque => [
                'tipo' => 'unidade',
                'codigo_texto' => GeradorCodigoEstoque::codigoUnidade($entidade),
                'titulo' => $entidade->codigo_lote ?? $entidade->serial_unico ?? $entidade->identificador_logistico ?? $entidade->id,
                'subtitulo' => $entidade->material?->codigo . ' — ' . $entidade->material?->descricao,
                'qr_svg' => GeradorCodigoEstoque::qrSvg(GeradorCodigoEstoque::codigoUnidade($entidade)),
            ],
            $entidade instanceof LocalEstoque => [
                'tipo' => 'local',
                'codigo_texto' => GeradorCodigoEstoque::codigoLocal($entidade),
                'titulo' => $entidade->nome,
                'subtitulo' => $entidade->tipo?->label(),
                'qr_svg' => GeradorCodigoEstoque::qrSvg(GeradorCodigoEstoque::codigoLocal($entidade)),
            ],
        };
    }
}
