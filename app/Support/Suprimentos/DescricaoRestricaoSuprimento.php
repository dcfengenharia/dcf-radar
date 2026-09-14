<?php

namespace App\Support\Suprimentos;

use App\Enums\EstadoGerencialNecessidade;
use App\Models\Atividade;
use App\Models\AtividadeNecessidadeMaterial;
use App\Models\ItemSuprimento;
use App\Support\Estoque\CoberturaNecessidadeAtividadeQuery;

/**
 * Motor Definitivo de Risco de Suprimentos V1 (Seção 24/28 do pedido de
 * implementação) — traduz a decomposição quantitativa
 * (`EstadoAtendimentoNecessidadeMaterialQuery`) numa descrição de
 * Restrição PRECISA, nunca genérica ("item do pacote está em risco")
 * quando existe correspondência inequívoca com uma ou mais
 * `AtividadeNecessidadeMaterial`. Reutiliza 100% dos dados já calculados
 * pelos dois read-models existentes — nunca uma terceira fonte de
 * verdade, nunca uma query nova.
 *
 * **Sem correspondência inequívoca** (`necessidadesRelevantesParaPacote()`
 * vazio) — usa sempre a `$fallbackGenerico` do chamador (o texto
 * histórico já usado antes desta etapa), nunca inventa quantidade sobre
 * uma correspondência que não existe (mesmo princípio de
 * `estadoCobreTodasParaPacote()` retornando `null` nesse caso).
 *
 * **Mesma restrição evolui a causa, nunca cria uma segunda** (Seção 28,
 * decisão A — "preferir rastreabilidade e baixo ruído"): o texto
 * retornado aqui é sempre recalculado do zero a cada sincronização e
 * comparado contra a descrição já gravada pelo chamador — se a causa
 * mudou (ex.: de "sem Pedido" pra "Pedido atrasado"), a MESMA linha de
 * Restrição só tem sua `descricao` atualizada, nunca uma nova é criada.
 */
class DescricaoRestricaoSuprimento
{
    public static function paraPacoteEAtividade(Atividade $atividade, ItemSuprimento $pacote, string $fallbackGenerico): string
    {
        $relevantes = CoberturaNecessidadeAtividadeQuery::necessidadesRelevantesParaPacote($atividade, $pacote);

        if ($relevantes->isEmpty()) {
            return $fallbackGenerico;
        }

        $linhasPorNecessidade = EstadoAtendimentoNecessidadeMaterialQuery::porAtividade($atividade)
            ->keyBy(fn (array $linha) => $linha['necessidade']->id);

        $frases = $relevantes
            ->map(function (AtividadeNecessidadeMaterial $necessidade) use ($linhasPorNecessidade) {
                $linha = $linhasPorNecessidade->get($necessidade->id);

                return $linha ? self::fraseParaLinha($necessidade, $linha) : null;
            })
            ->filter()
            ->values();

        if ($frases->isEmpty()) {
            return $fallbackGenerico;
        }

        return $frases->implode(' ');
    }

    private static function fraseParaLinha(AtividadeNecessidadeMaterial $necessidade, array $linha): ?string
    {
        $estado = $linha['estado_gerencial'];

        // Necessidade já protegida/disponível/recebida — nunca compõe
        // frase de bloqueio (Seção 25: nunca "não vai chegar" pra
        // material que já está fisicamente coberto, disponível em
        // estoque, ou já recebido fisicamente aguardando só a formalização
        // de "Dar entrada" — Fechamento Adversarial Final, Achado 1: o
        // risco de CHEGADA já não existe mais nesses 3 casos).
        if (in_array($estado, [
            EstadoGerencialNecessidade::Protegida,
            EstadoGerencialNecessidade::DisponivelNaoReservada,
            EstadoGerencialNecessidade::RecebidaAguardandoDisponibilizacao,
        ], true)) {
            return null;
        }

        $material = $necessidade->material();
        $nomeMaterial = $material?->descricao ?? $material?->codigo ?? 'material';
        $unidade = $necessidade->unidadeMedida?->codigo ?? '';

        $quantidade = match ($estado) {
            EstadoGerencialNecessidade::DependenteFornecimentoNoPrazo => $linha['decomposicao_dependente_no_prazo'],
            EstadoGerencialNecessidade::DependenteFornecimentoAtrasado => $linha['decomposicao_dependente_atrasado'],
            EstadoGerencialNecessidade::SemPrazo => $linha['decomposicao_pedida_sem_prazo'],
            EstadoGerencialNecessidade::PrePedido => $linha['decomposicao_adjudicada_sem_pedido'],
            EstadoGerencialNecessidade::EmProcesso => $linha['decomposicao_em_processo'],
            EstadoGerencialNecessidade::NaoContratada => $linha['decomposicao_sem_cobertura'],
            EstadoGerencialNecessidade::InformacaoInsuficiente => $linha['decomposicao_informacao_insuficiente'],
            default => 0.0,
        };

        if ($quantidade <= 0.0005) {
            return null;
        }

        $quantidadeFormatada = rtrim(rtrim(number_format($quantidade, 3, ',', '.'), '0'), ',');
        $dataPrometida = $linha['data_prometida_relevante']?->format('d/m/Y');

        return match ($estado) {
            EstadoGerencialNecessidade::DependenteFornecimentoAtrasado => $dataPrometida
                ? "{$quantidadeFormatada} {$unidade} de {$nomeMaterial} têm entrega prevista para {$dataPrometida}, após o início da atividade."
                : "{$quantidadeFormatada} {$unidade} de {$nomeMaterial} têm entrega comercialmente atrasada ou com previsão indeterminada.",
            EstadoGerencialNecessidade::DependenteFornecimentoNoPrazo => "{$quantidadeFormatada} {$unidade} de {$nomeMaterial} ainda dependem da entrega do Pedido (previsão: {$dataPrometida}).",
            EstadoGerencialNecessidade::SemPrazo => "{$quantidadeFormatada} {$unidade} de {$nomeMaterial} possuem Pedido emitido sem previsão de entrega.",
            EstadoGerencialNecessidade::PrePedido => "{$quantidadeFormatada} {$unidade} de {$nomeMaterial} foram adjudicadas a um fornecedor, mas ainda não possuem Pedido emitido.",
            EstadoGerencialNecessidade::EmProcesso => "{$quantidadeFormatada} {$unidade} de {$nomeMaterial} já estão em Requisição de Compra, mas ainda sem fornecedor definido.",
            EstadoGerencialNecessidade::NaoContratada => "{$quantidadeFormatada} {$unidade} de {$nomeMaterial} ainda não possuem nenhuma Requisição de Compra emitida.",
            EstadoGerencialNecessidade::InformacaoInsuficiente => "{$quantidadeFormatada} {$unidade} de {$nomeMaterial} têm informação insuficiente para confirmar cobertura (verificar unidade/data de necessidade).",
            default => null,
        };
    }
}
