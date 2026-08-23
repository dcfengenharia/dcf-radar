<?php

namespace App\Services;

use App\Models\Work;
use App\Support\Grd\CandidatosNovaEntregaGrd;
use App\Support\Grd\DetectorCopiasObsoletasGrd;
use App\Support\Grd\ResumoDigestPendenciasGed;
use Carbon\Carbon;

/**
 * Ciclo 18, Etapa 18.5.7 — núcleo de consolidação do Digest Semanal de
 * Pendências GED: responde, pra UMA obra, "quanto ainda está pendente
 * agora?". Espelha o papel de `App\Services\DigestProntidao` (Ciclo 16,
 * A.2) — mesma filosofia "ABSOLUTA" da 18.5.7: **nenhuma regra nova**.
 * Consome integralmente `DetectorCopiasObsoletasGrd::porObra()`/
 * `CandidatosNovaEntregaGrd::porObra()` (18.5.1, já aprovados e usados
 * pela Central Operacional 18.5.4 e pelos Alertas A/B 18.5.5) — a mesma
 * fonte que a Central Operacional usa, garantindo que os dois concordem
 * por construção, nunca por coincidência.
 *
 * Deliberadamente NÃO é um Scheduler: não itera tenants/obras, não
 * resolve destinatários, não envia nada, não decide cadência — isso é
 * responsabilidade de `App\Console\Commands\NotificarPendenciasGedCommand`.
 */
class DigestPendenciasGed
{
    public function __construct(
        private readonly DetectorCopiasObsoletasGrd $detectorObsoletas,
        private readonly CandidatosNovaEntregaGrd $candidatosNovaEntrega,
    ) {
    }

    /**
     * Recebe UMA obra explicitamente — nunca itera tenants/obras. O
     * isolamento tenant/obra do resultado vem inteiramente dos 2 serviços
     * consumidos, nunca reimplementado aqui.
     *
     * Contagens (nunca a lista de distribuições/candidatos individuais —
     * o digest é "quantos", não "quais", mesmo espírito de "não listar
     * centenas de linhas"):
     * - obsoletas: nº de Documentos distintos, nº de destinatários
     *   distintos (por `GrdDestinatario.destinatario_id`, a PESSOA — não
     *   o snapshot da distribuição, que pode ter várias linhas pro mesmo
     *   destinatário em GRDs diferentes) e a soma de `quantidade_pendente`
     *   (a mesma métrica física já usada pela Central Operacional/Alerta A).
     * - candidatos: nº de Documentos distintos, nº de destinatários
     *   distintos — `CandidatosNovaEntregaGrd::porObra()` já retorna 1
     *   registro por par documento×destinatário, então basta contar
     *   `unique()` em cada eixo.
     */
    public function consolidar(Work $obra): ResumoDigestPendenciasGed
    {
        $obsoletas = $this->detectorObsoletas->porObra($obra);
        $candidatos = $this->candidatosNovaEntrega->porObra($obra);

        return new ResumoDigestPendenciasGed(
            obraId: $obra->id,
            obraNome: $obra->name,
            geradoEm: Carbon::now(),
            obsoletasDocumentos: $obsoletas->pluck('documento.id')->unique()->count(),
            obsoletasDestinatarios: $obsoletas->pluck('grd_destinatario.destinatario_id')->unique()->count(),
            obsoletasQuantidadeFisica: (int) $obsoletas->sum('quantidade_pendente'),
            candidatosDocumentos: $candidatos->pluck('documento.id')->unique()->count(),
            candidatosDestinatarios: $candidatos->pluck('destinatario.id')->unique()->count(),
        );
    }
}
