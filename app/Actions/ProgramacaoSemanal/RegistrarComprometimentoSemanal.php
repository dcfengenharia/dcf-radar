<?php

namespace App\Actions\ProgramacaoSemanal;

use App\Enums\OrigemProgramacaoSemanalItem;
use App\Enums\StatusProgramacaoSemanal;
use App\Models\Atividade;
use App\Models\ProgramacaoSemanal;
use App\Models\Work;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Congela (insert imutável, nunca update) o lado PREVISTO do que foi
 * comprometido pra uma semana — datas planejadas + HH previsto daquela
 * semana, no momento do commit. O lado REALIZADO nunca é gravado aqui:
 * continua sendo lido ao vivo (status atual da Atividade, AvancoPeriodo
 * realizado) sempre que alguém for comparar previsto x realizado depois,
 * já que só o "o que foi prometido" precisa parar de mudar com o tempo —
 * mesma filosofia de AtividadeSnapshot/LinhaBase (CLAUDE.md).
 *
 * Idempotente: comprometer a mesma atividade duas vezes na mesma semana
 * nunca duplica (insertOrIgnore + unique constraint).
 *
 * Sempre grava na versão VIGENTE da semana (`ProgramacaoSemanal::
 * ativaPara()` — a de `versao` mais alta); nunca insere numa
 * programação já Fechada — é preciso criar uma Revisão primeiro
 * (App\Actions\ProgramacaoSemanal\CriarRevisaoProgramacaoSemanal).
 *
 * Ciclo 18, Etapa 18.4.CORREÇÃO — defesa em profundidade: os dois únicos
 * chamadores reais (⚡plano-semanal.blade.php::comprometerSelecionadas(),
 * ⚡lookahead.blade.php::gerarPlanoSemanal()) já filtram `$atividades` por
 * `Atividade::scopeProntas()` (a fonte canônica de prontidão, que desde
 * esta correção considera GED) antes de chamar esta Action — mas esta
 * Action nunca confiava só nisso (mesmo princípio já aplicado em
 * `AlterarLiberacaoRevisaoDocumento::garantirRevisaoVigente()`). Por
 * isso, `execute()` refiltra `$atividades` contra `scopeProntas()` de
 * novo aqui, em 1 única query em lote — nunca confia que quem chamou já
 * filtrou corretamente. Uma atividade não-pronta na coleção recebida é
 * silenciosamente ignorada (nunca vira erro que interrompa o commit das
 * demais, mesmo espírito de `insertOrIgnore` já usado abaixo).
 */
class RegistrarComprometimentoSemanal
{
    public function execute(
        Work $obra,
        string $semanaInicio,
        Collection $atividades,
        OrigemProgramacaoSemanalItem $origem
    ): ProgramacaoSemanal {
        $header = ProgramacaoSemanal::ativaPara($obra, $semanaInicio);

        if ($header && $header->estaFechada()) {
            throw new RuntimeException('Esta programação está fechada. Crie uma revisão pra adicionar novos itens.');
        }

        if (! $header) {
            $semanaFim = Carbon::parse($semanaInicio)->endOfWeek()->toDateString();

            $header = ProgramacaoSemanal::create([
                'obra_id' => $obra->id,
                'semana_inicio' => $semanaInicio,
                'semana_fim' => $semanaFim,
                'congelada_em' => now(),
                'criado_por' => Auth::id(),
                'status' => StatusProgramacaoSemanal::Aberta->value,
                'versao' => 1,
            ]);
        } else {
            $header->touch();
        }

        if ($atividades->isEmpty()) {
            return $header;
        }

        $idsProntas = Atividade::query()
            ->where('obra_id', $obra->id)
            ->whereIn('id', $atividades->pluck('id'))
            ->prontas()
            ->pluck('id');
        $atividades = $atividades->filter(fn (Atividade $a) => $idsProntas->contains($a->id))->values();

        if ($atividades->isEmpty()) {
            return $header;
        }

        $linhas = ProgramacaoSemanalSnapshot::linhasParaItens($header, $semanaInicio, $atividades, $origem);

        DB::table('programacao_semanal_itens')->insertOrIgnore($linhas);

        return $header;
    }
}
