<?php

namespace App\Support\Grd;

use App\Models\GrdAceiteEntrega;
use App\Models\GrdDestinatario;
use App\Models\GrdDistribuicao;
use BaconQrCode\Renderer\Color\Rgb;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\Fill;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Storage;

/**
 * Ciclo 18, Etapa 18.5.8 — monta os dados do COMPROVANTE DE ENTREGA de um
 * destinatário dentro de UMA GRD Emitida. Puramente de LEITURA — nenhuma
 * regra de domínio nova, nenhuma escrita, mesmo espírito de
 * `MontarDadosPdfGrd` (18.5.3).
 *
 * **Unidade do comprovante — decisão de domínio, não arbitrária**: UM
 * comprovante de entrega representa TODOS os itens que UM destinatário
 * recebeu em UMA GRD (a chave é `GrdDestinatario`, já ULID, já pertence a
 * exatamente 1 Grd — nunca uma chave composta nova). Um destinatário que
 * recebeu 3 documentos na mesma GRD ganha 1 comprovante com 3 linhas,
 * nunca 3 comprovantes separados — é assim que um recibo de entrega físico
 * funciona na prática (quem recebe assina 1 vez por entrega, não 1 vez por
 * papel).
 *
 * **Emissão da GRD == o fato de entrega, nesta etapa** — decisão
 * confirmada por evidência do próprio domínio (não presumida): o docblock
 * de `GrdDistribuicao` já descreve a si mesma como "o fato atômico 'este
 * destinatário RECEBEU este item'"; o método já se chama
 * `quantidadeEntregue()`; `CandidatosNovaEntregaGrd` (18.5.1) já trata
 * "GRD Emitida" como sinônimo de "já recebeu" em toda sua lógica. Não
 * existe, em nenhum ponto do domínio, um segundo fato de "confirmação de
 * entrega física" distinto da emissão — por isso um comprovante de entrega
 * é gerável diretamente a partir dos snapshots já congelados na emissão,
 * sem necessidade de um novo fato/tabela.
 *
 * 100% reconstruído a partir de snapshots/fatos já congelados
 * (`GrdItem.*_snapshot`, `GrdDestinatario.*_snapshot`,
 * `GrdDistribuicao.quantidade`) — NUNCA consulta `revisaoVigente()`/
 * `Destinatario` ao vivo. R2 nascer depois nunca "contamina" o comprovante
 * de uma entrega de R1 (o item aponta pra revisão EXATA, PK fixa).
 */
class MontarDadosComprovanteEntrega
{
    public const DISCO_ASSINATURA = 'local';

    /**
     * Ciclo 18, Etapa 18.5.9 — evolução aditiva: além dos dados já
     * existentes (18.5.8), resolve o aceite ATIVO deste destinatário
     * (`GrdAceiteEntrega::whereNull('invalidado_em')` — a UNIQUE
     * estrutural da migration garante que no máximo 1 linha bate essa
     * condição) e, quando houver, monta o PNG da assinatura em base64
     * (pra embutir inline no PDF via DomPDF, nunca expondo o path privado)
     * e o SVG do QR Code de verificação (`route('publico.grd-verificacao')`,
     * mesma técnica de `BaconQrCode` já usada por `laravel/fortify` neste
     * projeto — nenhuma dependência nova). Sem aceite ativo, `aceiteAtivo`
     * vem `null` — a view mostra "Sem aceite ativo", nunca inventa dado.
     *
     * @return array{grd: \App\Models\Grd, grdDestinatario: GrdDestinatario, distribuicoes: \Illuminate\Support\Collection<int, GrdDistribuicao>, geradoEm: \Illuminate\Support\Carbon, aceiteAtivo: ?GrdAceiteEntrega, assinaturaBase64: ?string, qrSvg: ?string}
     */
    public function paraDestinatario(GrdDestinatario $grdDestinatario): array
    {
        $grdDestinatario->loadMissing(['grd.obra', 'grd.emitidoPor']);

        $distribuicoes = GrdDistribuicao::where('grd_destinatario_id', $grdDestinatario->id)
            ->with('item')
            ->orderBy('created_at')
            ->get();

        $aceiteAtivo = GrdAceiteEntrega::where('grd_destinatario_id', $grdDestinatario->id)
            ->whereNull('invalidado_em')
            ->first();

        $assinaturaBase64 = null;
        $assinaturaIntegra = null;
        $qrSvg = null;

        if ($aceiteAtivo !== null) {
            if ($aceiteAtivo->assinatura_path && Storage::disk(self::DISCO_ASSINATURA)->exists($aceiteAtivo->assinatura_path)) {
                $binario = Storage::disk(self::DISCO_ASSINATURA)->get($aceiteAtivo->assinatura_path);
                $assinaturaBase64 = base64_encode($binario);

                // Checksum de INTEGRIDADE do arquivo (nunca assinatura digital/
                // certificado) — detecta alteração física dos bytes do PNG depois
                // de gravado. Divergência nunca vira 500: a view mostra um aviso
                // textual e simplesmente não exibe a imagem potencialmente adulterada.
                $assinaturaIntegra = $aceiteAtivo->assinatura_hash === null
                    || hash('sha256', $binario) === $aceiteAtivo->assinatura_hash;

                if (! $assinaturaIntegra) {
                    $assinaturaBase64 = null;
                }
            }

            $qrSvg = $this->gerarQrSvg(route('publico.grd-verificacao', ['token' => $aceiteAtivo->token]));
        }

        return [
            'grd' => $grdDestinatario->grd,
            'grdDestinatario' => $grdDestinatario,
            'distribuicoes' => $distribuicoes,
            'geradoEm' => now(),
            'aceiteAtivo' => $aceiteAtivo,
            'assinaturaBase64' => $assinaturaBase64,
            'assinaturaIntegra' => $assinaturaIntegra,
            'qrSvg' => $qrSvg,
        ];
    }

    private function gerarQrSvg(string $url): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(140, 0, null, null, Fill::uniformColor(new Rgb(255, 255, 255), new Rgb(0, 0, 0))),
            new SvgImageBackEnd()
        );

        return (new Writer($renderer))->writeString($url);
    }
}
