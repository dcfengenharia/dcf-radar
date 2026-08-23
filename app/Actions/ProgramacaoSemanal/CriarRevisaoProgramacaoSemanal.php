<?php

namespace App\Actions\ProgramacaoSemanal;

use App\Enums\OrigemProgramacaoSemanalItem;
use App\Enums\StatusProgramacaoSemanal;
use App\Models\Atividade;
use App\Models\ProgramacaoSemanal;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cria uma nova versão (revisão) de uma programação já Fechada — nasce
 * Aberta, com as MESMAS atividades da original, mas com datas/HH
 * RE-CAPTURADOS ao vivo da Atividade no momento da revisão (não copia
 * os valores congelados antigos — decisão do usuário: replanejamento
 * parte de onde o cronograma está agora). A versão original nunca é
 * alterada — fica no histórico, sempre acessível via `revisaoDe()`/
 * `revisoes()`.
 *
 * Só pode revisar a versão MAIS RECENTE daquela obra+semana (nunca uma
 * já superada por outra revisão) — histórico fica linear, sem
 * ramificação.
 */
class CriarRevisaoProgramacaoSemanal
{
    public function execute(ProgramacaoSemanal $original): ProgramacaoSemanal
    {
        if (! $original->estaFechada()) {
            throw new RuntimeException('Só é possível revisar uma programação fechada.');
        }

        $vigente = ProgramacaoSemanal::ativaPara($original->obra, $original->semana_inicio->toDateString());

        if (! $vigente || $vigente->id !== $original->id) {
            throw new RuntimeException('Só é possível revisar a versão mais recente desta semana.');
        }

        return DB::transaction(function () use ($original) {
            $agora = now();

            // Ciclo 17, A.9.5 — carimba o instante em que ESTA versão
            // (original) deixou de ser vigente, mesmo timestamp da
            // congelada_em da revisão nova — nunca um `now()` separado,
            // pra não abrir um micro-gap onde nenhuma das duas seria
            // vigente. Ver App\Models\ProgramacaoSemanal::vigenteEm().
            $original->update(['superseded_at' => $agora]);

            $revisao = ProgramacaoSemanal::create([
                'tenant_id' => $original->tenant_id,
                'obra_id' => $original->obra_id,
                'semana_inicio' => $original->semana_inicio,
                'semana_fim' => $original->semana_fim,
                'congelada_em' => $agora,
                'criado_por' => Auth::id(),
                'status' => StatusProgramacaoSemanal::Aberta->value,
                'versao' => $original->versao + 1,
                'revisao_de_id' => $original->id,
            ]);

            $atividades = Atividade::whereIn('id', $original->itens()->pluck('atividade_id'))->get();

            if ($atividades->isNotEmpty()) {
                $linhas = ProgramacaoSemanalSnapshot::linhasParaItens(
                    $revisao, $original->semana_inicio->toDateString(), $atividades, OrigemProgramacaoSemanalItem::Revisao
                );

                DB::table('programacao_semanal_itens')->insert($linhas);
            }

            return $revisao;
        });
    }
}
