<?php

namespace App\Support\CentralProntidao;

/**
 * Ciclo 18, Etapa 18.4 — leitura resumida de UM DocumentoEngenharia
 * vinculado DIRETAMENTE (`Atividade::documentosEngenharia()`, pivô
 * `documento_engenharia_atividades`, Ciclo 18.1) que NÃO está liberado
 * para construção — puramente estrutura de dados, sem nenhuma lógica de
 * consulta ao banco (isso vive só em CentralProntidaoQuery).
 *
 * Deliberadamente DIFERENTE de `EngenhariaAlerta` (Ciclo 15, B.1): aquele
 * chega via Atividade -> ItemSuprimento -> DocumentoEngenharia (vínculo
 * indireto, semântica de suprimento — atrasado/não emitido) e nunca afeta
 * `pronta`/`statusOperacional`; este chega via o vínculo DIRETO 18.1 e é a
 * ÚNICA fonte usada por `CentralProntidaoQuery::montarView()` pra decidir
 * se a Atividade tem pendência documental de liberação — os dois convivem
 * sem se misturar, cada um com seu próprio campo em `AtividadeProntidaoView`.
 *
 * `$motivo` é sempre o valor cru de `DocumentoEngenharia::motivoLiberacao()`
 * ('sem_revisao' ou 'revisao_nao_liberada' — nunca 'revisao_liberada',
 * porque só documentos NÃO liberados entram nesta lista) — nunca inferido
 * por status/texto visual, conforme regra canônica da 18.4.
 */
final readonly class DocumentoEngenhariaBloqueio
{
    public function __construct(
        public string $documentoId,
        public ?string $codigo,
        public ?string $descricao,
        public ?string $revisaoVigente,
        public ?string $statusDocumental,
        public string $motivo,
    ) {
    }
}
