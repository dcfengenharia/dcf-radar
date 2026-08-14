<?php

namespace App\Support\CentralProntidao;

/**
 * Leitura resumida de UM DocumentoEngenharia atrasado/não emitido,
 * alcançado via Atividade -> ItemSuprimento -> DocumentoEngenharia (não
 * existe relação direta Documento->Atividade — Ciclo 13/14) — MVP mostra
 * só documentos com alerta (ver CentralProntidaoQuery). Puramente
 * CONTEXTO — nunca altera `AtividadeProntidaoView::$pronta` (Ciclo 14,
 * princípio 6).
 */
final readonly class EngenhariaAlerta
{
    public function __construct(
        public string $documentoId,
        public ?string $codigo,
        public bool $atrasado,
        public bool $emitido,
    ) {
    }
}
