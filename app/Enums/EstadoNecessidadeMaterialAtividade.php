<?php

namespace App\Enums;

/**
 * Melhoria "Posto Operacional" — taxonomia de cobertura de UMA linha de
 * `AtividadeNecessidadeMaterial`, calculada por
 * `App\Support\Estoque\CoberturaNecessidadeAtividadeQuery`. Deliberadamente
 * SEPARADA de `App\Enums\EstadoCoberturaMaterial` (Ciclo 21.1) — as duas
 * respondem perguntas diferentes: aquela é "saúde do pipeline de compra
 * de um Pacote inteiro"; esta é "esta atividade específica tem o que
 * precisa, agora, considerando o físico/reservado da obra inteira".
 * Nunca confundidas/misturadas no mesmo enum.
 *
 * `Coberta` significa cobertura ESPECÍFICA e real da necessidade desta
 * atividade (reservado_atividade >= necessário) — nunca "estoque físico
 * existe em algum lugar", que por si só nunca é suficiente pra chamar
 * de coberta (ver `DisponivelParaReserva`, o estado que faltava no
 * enum irmão, exatamente por essa distinção).
 *
 * `SemNecessidadeCadastrada` é só um estado AGREGADO possível da
 * atividade inteira (quando ela não tem nenhuma linha de necessidade) —
 * nunca o estado de uma linha individual, que sempre tem uma
 * necessidade por definição (é a própria linha).
 */
enum EstadoNecessidadeMaterialAtividade: string
{
    case Coberta = 'coberta';
    case DisponivelParaReserva = 'disponivel_para_reserva';
    case Parcial = 'parcial';
    case SemEstoque = 'sem_estoque';
    case UnidadeIncompativel = 'unidade_incompativel';
    case SemNecessidadeCadastrada = 'sem_necessidade_cadastrada';

    public function label(): string
    {
        return match ($this) {
            self::Coberta => 'Coberta',
            self::DisponivelParaReserva => 'Disponível para reserva',
            self::Parcial => 'Parcial',
            self::SemEstoque => 'Sem estoque',
            self::UnidadeIncompativel => 'Unidade incompatível',
            self::SemNecessidadeCadastrada => 'Sem necessidade cadastrada',
        };
    }

    /** Cor Bootstrap (bg-label-*) pro badge — mesma convenção do resto do projeto. */
    public function cor(): string
    {
        return match ($this) {
            self::Coberta => 'success',
            self::DisponivelParaReserva => 'info',
            self::Parcial => 'warning',
            self::SemEstoque => 'danger',
            self::UnidadeIncompativel => 'dark',
            self::SemNecessidadeCadastrada => 'secondary',
        };
    }
}
