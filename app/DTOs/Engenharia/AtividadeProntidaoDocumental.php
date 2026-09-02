<?php

namespace App\DTOs\Engenharia;

use App\Enums\EstadoProntidaoEngenharia;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Ciclo 22, Etapa 22.1 — read model de 1 Atividade dentro do horizonte,
 * com a prontidão DOCUMENTAL detalhada (Seção 8/15 do pedido) — nunca só
 * o booleano duro de `Atividade::scopeProntas()`.
 */
final readonly class AtividadeProntidaoDocumental
{
    /** @param Collection<int, DocumentoDependenciaAtividade> $documentos */
    public function __construct(
        public string $atividadeId,
        public string $codigo,
        public string $nome,
        public ?Carbon $inicioPlanejado,
        public ?string $frenteNome,
        public ?string $pacoteNome,
        public Collection $documentos,
        public EstadoProntidaoEngenharia $estado,
        public ?int $diasParaInicio,
    ) {
    }

    /** @return Collection<int, DocumentoDependenciaAtividade> */
    public function documentosBloqueantes(): Collection
    {
        return $this->documentos->reject(fn (DocumentoDependenciaAtividade $d) => $d->liberado)->values();
    }

    public function totalDocumentos(): int
    {
        return $this->documentos->count();
    }

    public function totalLiberados(): int
    {
        return $this->documentos->filter(fn (DocumentoDependenciaAtividade $d) => $d->liberado)->count();
    }
}
