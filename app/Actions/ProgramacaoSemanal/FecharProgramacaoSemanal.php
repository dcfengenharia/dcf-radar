<?php

namespace App\Actions\ProgramacaoSemanal;

use App\Enums\StatusProgramacaoSemanal;
use App\Models\ProgramacaoSemanal;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Fecha formalmente uma programação semanal (ação explícita do usuário,
 * botão "Gerar Programação" no Plano Semanal) — a partir daqui, só resta
 * marcar as atividades como concluídas/não concluídas (Atividade.status,
 * fluxo já existente); nada mais pode ser alterado nessa versão. Pra
 * comprometer atividades novas depois disso, é preciso criar uma revisão
 * (CriarRevisaoProgramacaoSemanal).
 */
class FecharProgramacaoSemanal
{
    public function execute(ProgramacaoSemanal $programacao): ProgramacaoSemanal
    {
        if ($programacao->estaFechada()) {
            throw new RuntimeException('Esta programação já está fechada.');
        }

        if ($programacao->itens()->count() === 0) {
            throw new RuntimeException('Não é possível fechar uma programação sem nenhum item.');
        }

        $programacao->update([
            'status' => StatusProgramacaoSemanal::Fechada->value,
            'fechada_em' => now(),
            'fechada_por' => Auth::id(),
        ]);

        return $programacao;
    }
}
