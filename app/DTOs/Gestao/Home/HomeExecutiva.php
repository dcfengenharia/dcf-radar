<?php

namespace App\DTOs\Gestao\Home;

/**
 * Home Executiva (Ciclo 25) — read model ÚNICO da página inicial da
 * plataforma (`app.home`). Nunca calcula regra de negócio própria: cada
 * campo é composição de fontes já autoritativas (ver docblock de
 * `App\Support\Gestao\HomeExecutivaQuery`, o único produtor deste DTO).
 *
 * Listas de item (`ameacas`/`causas`/`acoesRecomendadas`/
 * `ultimosAcontecimentos`) usam array associativo simples por linha
 * (mesmo estilo já aceito em `CockpitObra::$panorama`/
 * `SituacaoGerencial::$contexto`) em vez de uma classe dedicada por item
 * — são estruturas de apresentação, nunca relidas por outro serviço.
 */
final class HomeExecutiva
{
    /**
     * @param  array<int, array<string, mixed>>  $ameacas
     * @param  array<string, HomeHorizonte>  $horizontes  chaves: semana, duas_semanas, quatro_semanas, oito_semanas
     * @param  array<int, array<string, mixed>>  $causas
     * @param  array<string, mixed>  $suprimentos
     * @param  array<string, mixed>  $engenharia
     * @param  array<int, array<string, mixed>>  $acoesRecomendadas
     * @param  array<int, array<string, mixed>>  $ultimosAcontecimentos
     * @param  array<string, mixed>|null  $execucaoSemana
     * @param  array<string, mixed>  $prontidaoRecuperavel
     */
    public function __construct(
        public readonly string $obraId,
        public readonly string $obraNome,
        public readonly bool $temCronograma,
        public readonly HomeHorizonte $heroProntidao,
        public readonly string $fraseGerencial,
        public readonly array $ameacas,
        public readonly array $horizontes,
        public readonly array $causas,
        public readonly array $suprimentos,
        public readonly array $engenharia,
        public readonly array $acoesRecomendadas,
        public readonly array $ultimosAcontecimentos,
        public readonly ?array $execucaoSemana,
        public readonly array $prontidaoRecuperavel,
        /** Fechamento (Ciclo 25) — `true` quando esta obra nunca havia sido aberta antes por este usuário (janela de "últimos acontecimentos" honesta de 7 dias); `false` quando `ultimosAcontecimentos` já reflete "desde sua última visita" de verdade. */
        public readonly bool $primeiroAcessoHome = false,
    ) {
    }
}
