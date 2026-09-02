<?php

namespace App\DTOs\Engenharia;

use Carbon\Carbon;

/**
 * Ciclo 22, Etapa 22.1 — 1 documento vinculado a 1 Atividade, já com o
 * julgamento de liberação resolvido (nunca recalculado pelo consumidor).
 *
 * **Ciclo 22, Etapa 22.2 — `totalRevisoesDocumento` (aditivo, extensão
 * justificada)**: adicionado exclusivamente pra permitir à UI escolher
 * texto SEMANTICAMENTE CORRETO sem inventar um estado "substituída"
 * inexistente no domínio (Seção 4 do pedido 22.2 — fresh-read confirmou
 * que `scopeVigentes()`/`ultimaLiberacao()` nunca marcam uma revisão
 * anterior como "inválida"; a liberação de R1 permanece intacta no
 * histórico dela, só deixa de controlar o Documento). Com
 * `totalRevisoesDocumento === 1`, a única leitura correta é "aguardando
 * a primeira liberação"; com `> 1`, é correto dizer "existe uma revisão
 * mais recente ainda não liberada" — nunca "revisão substituída/
 * inválida/documento desatualizado". Zero mudança na regra de
 * liberação/vigência em si.
 */
final readonly class DocumentoDependenciaAtividade
{
    public function __construct(
        public string $documentoId,
        public string $codigo,
        public ?string $revisaoId,
        public ?string $revisaoTexto,
        public bool $liberado,
        public string $motivoLiberacao,
        public ?Carbon $liberadoEm,
        public int $totalRevisoesDocumento,
    ) {
    }
}
