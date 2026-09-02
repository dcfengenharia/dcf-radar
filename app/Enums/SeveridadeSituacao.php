<?php

namespace App\Enums;

/**
 * Ciclo 21, Etapa 21.2 — taxonomia única de severidade das situações
 * gerenciais (Seção 6). Investigado antes de criar: o projeto já tem
 * `HealthCheckSeveridade` (5 níveis, domínio de Health Check do
 * cronograma) e `SeveridadeInconsistenciaAvanco` (3 níveis,
 * informativa/atencao/critica, domínio de avanço físico) — nenhum dos
 * dois é reutilizável aqui sem forçar um domínio no outro (mesmo
 * princípio já seguido em toda a Etapa 20: cada domínio tem sua própria
 * taxonomia, nunca uma reaproveitada por conveniência). 4 níveis
 * (Informativa/Atencao/Alta/Critica) — o pedido já sugere essa
 * granularidade e nenhum precedente exato com 4 níveis existia.
 *
 * **Sempre CALCULADA, nunca escolhida manualmente** — ver os métodos
 * privados `calcularSeveridade*()` de cada situação em
 * `App\Support\Gestao\SituacoesGerenciaisQuery`, todos documentados e
 * testáveis, nunca um `score = 37` opaco.
 */
enum SeveridadeSituacao: string
{
    case Informativa = 'informativa';
    case Atencao = 'atencao';
    case Alta = 'alta';
    case Critica = 'critica';

    public function label(): string
    {
        return match ($this) {
            self::Informativa => 'Informativa',
            self::Atencao => 'Atenção',
            self::Alta => 'Alta',
            self::Critica => 'Crítica',
        };
    }

    /** Usado só pra ordenação/agregação — nunca exibido como "score". */
    public function peso(): int
    {
        return match ($this) {
            self::Informativa => 0,
            self::Atencao => 1,
            self::Alta => 2,
            self::Critica => 3,
        };
    }

    /**
     * Ciclo 21, Etapa 21.3 — hierarquia visual da Central de Notificações
     * (Seção 30 do pedido: "mostrar prioridade sem transformar tudo em
     * vermelho"). `Alta` reserva o vermelho (`danger`); `Critica` usa
     * `dark` — mais forte que vermelho sem ser "mais um tom de vermelho",
     * mesmo idioma já usado no projeto pra severidade máxima (badge
     * `bg-label-dark` de "Conta Operadora").
     */
    public function cor(): string
    {
        return match ($this) {
            self::Informativa => 'secondary',
            self::Atencao => 'warning',
            self::Alta => 'danger',
            self::Critica => 'dark',
        };
    }

    public function icone(): string
    {
        return match ($this) {
            self::Informativa => 'bx-info-circle',
            self::Atencao => 'bx-error-circle',
            self::Alta => 'bx-error',
            self::Critica => 'bx-block',
        };
    }
}
