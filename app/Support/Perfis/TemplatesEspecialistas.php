<?php

namespace App\Support\Perfis;

use App\Models\Perfil;
use App\Models\Tenant;

/**
 * FASE 2C, Seção 6 — templates profissionais ALÉM dos 5 já cobertos por
 * `App\Enums\Papel`/`Perfil::seedPadrao()` (Administrador da Empresa =
 * admin; Gerente de Projeto/Contrato = gerente_planejamento; Engenharia =
 * engenheiro; Produção/Campo = encarregado; Cliente/Fiscalização =
 * cliente_leitura — nenhum dos 5 foi alterado nesta fase).
 *
 * Os 4 templates aqui (Planejamento especialista, Suprimentos,
 * Almoxarifado, Executivo) são papéis "LATERAIS" — autoridade alta num
 * domínio, nenhuma em outros — que não cabem na hierarquia LINEAR de
 * `Papel::nivel()` (um único número não expressa "muita autoridade em
 * Suprimentos, zero em Engenharia"). Por isso usam `Perfil::
 * criarComCapacidades()` com uma lista EXPLÍCITA de capacidades, nunca o
 * mecanismo de threshold de `seedPadrao()`.
 *
 * NUNCA seedados automaticamente pra nenhum tenant (Seção 7: "não
 * sobrescrever clientes existentes") — só instanciados quando o
 * administrador explicitamente escolhe "Novo Perfil → baseado em
 * template" (`criar()` abaixo). Cada instanciação usa `slug_padrao`
 * próprio (nunca um valor de `Papel::cases()`) — o Perfil resultante
 * ainda conta como "padrão DCF.ENG" (`Perfil::ehPadrao()`), mas nunca
 * colide com os 5 templates legados.
 */
class TemplatesEspecialistas
{
    /**
     * @return array<string, array{nome: string, descricao: string, verGatedExtra: array<int,string>, capacidades: array<int, array{0:string,1:string}>}>
     */
    public static function definicoes(): array
    {
        return [
            // Especialista de Planejamento — mais estreito que "Gerente de
            // Projeto/Contrato" (gerente_planejamento, que continua com
            // autoridade ampla em quase todo domínio): aqui só as
            // ferramentas de cronograma/RP/Report, sem editar diretamente
            // o Quadro de Restrições nem Suprimentos/Estoque (Seção 35:
            // "não conceder automaticamente Engenharia/Suprimentos/Estoque
            // operacional" — Planejamento acompanha, não opera esses
            // domínios).
            'planejamento' => [
                'nome' => 'Planejamento',
                'descricao' => 'Cronograma, linhas de base, curvas S e requisições — sem editar Restrições, Suprimentos ou Estoque diretamente.',
                'verGatedExtra' => ['gestao.cockpit'],
                'capacidades' => [
                    ['obras.importar_cronograma', 'criar'], ['obras.importar_cronograma', 'editar'], ['obras.importar_cronograma', 'excluir'],
                    ['obras.linhas_base', 'criar'], ['obras.linhas_base', 'editar'], ['obras.linhas_base', 'excluir'],
                    ['obras.curvas', 'editar'], ['obras.curvas', 'excluir'],
                    ['planejamento.requisicoes', 'editar'],
                    ['restricoes.lookahead', 'comentar'],
                    ['report.relatorios', 'criar'], ['report.relatorios', 'editar'], ['report.relatorios', 'comentar'], ['report.relatorios', 'excluir'],
                    ['report.importar_avanco', 'criar'], ['report.importar_avanco', 'editar'], ['report.importar_avanco', 'excluir'],
                ],
            ],

            // Suprimentos — opera o Mapa de Suprimentos de ponta a ponta
            // (RP→RC→Pedido, ver Ciclo 19), só enxerga (nunca edita) o
            // reflexo em Estoque (Almoxarifado é quem opera fisicamente,
            // Seção 40 do pedido de correção da Fase 2B: responsabilidades
            // deliberadamente separadas).
            'suprimentos' => [
                'nome' => 'Suprimentos',
                'descricao' => 'Requisições de compra, adjudicação e pedidos — visualiza o reflexo em Estoque, sem operá-lo.',
                'verGatedExtra' => [],
                'capacidades' => [
                    ['suprimentos.mapa', 'criar'], ['suprimentos.mapa', 'editar'], ['suprimentos.mapa', 'comentar'], ['suprimentos.mapa', 'excluir'],
                ],
            ],

            // Almoxarifado — opera fisicamente o Estoque (entrada,
            // conciliação/aplicação, inventário, industrialização), só
            // enxerga (nunca edita) o Mapa de Suprimentos que originou a
            // demanda.
            'almoxarifado' => [
                'nome' => 'Almoxarifado',
                'descricao' => 'Entrada, conciliação, inventário e industrialização em Estoque — sem editar Suprimentos.',
                'verGatedExtra' => [],
                'capacidades' => [
                    ['estoque.movimentacao', 'criar'], ['estoque.movimentacao', 'editar'],
                    ['estoque.conciliacao', 'criar'], ['estoque.conciliacao', 'editar'],
                    ['estoque.inventario', 'criar'], ['estoque.inventario', 'editar'],
                    ['estoque.industrializacao', 'criar'], ['estoque.industrializacao', 'editar'],
                ],
            ],

            // Executivo — Seção 33: Cockpits/Home executivos permitidos,
            // SEM conceder acesso operacional automaticamente. Usa o modo
            // ALLOWLIST de `Perfil::criarComCapacidades()` (`verLivrePorPadrao
            // = false`) — nunca herda a franquia "ver livre em tudo, exceto
            // Cockpits" que os demais templates recebem; só enxerga
            // EXPLICITAMENTE os Cockpits + páginas de indicador/leitura
            // gerencial (Home, Benchmarking, Lições Aprendidas, Relatórios,
            // Central de Prontidão) — zero 'ver' em qualquer tela
            // operacional (suprimentos.mapa/estoque.*/engenharia.pacotes/
            // restricoes.quadro/etc.). O detalhe operacional DENTRO dos
            // próprios Cockpits continua redigido pela mesma regra já usada
            // por `App\Support\Gestao\RedacaoOperacionalCockpit` (Ciclo 21)
            // — as duas camadas se reforçam, nenhuma delas depende
            // sozinha da outra.
            'executivo' => [
                'nome' => 'Executivo',
                'descricao' => 'Cockpits, relatórios e indicadores gerenciais — sem acesso às telas operacionais de cada domínio.',
                'verLivrePorPadrao' => false,
                'verGatedExtra' => [
                    'dashboard.gerencial', 'gestao.cockpit', 'gestao.suprimentos', 'gestao.engenharia',
                    'obras.minhas_obras', 'gestao.benchmarking', 'gestao.licoes-aprendidas',
                    'report.relatorios', 'restricoes.relatorios', 'restricoes.central_prontidao',
                ],
                'capacidades' => [
                    ['report.relatorios', 'comentar'],
                ],
            ],
        ];
    }

    public static function definicao(string $chave): ?array
    {
        return self::definicoes()[$chave] ?? null;
    }

    /**
     * Instancia o template especialista `$chave` como um Perfil novo pro
     * tenant — sempre uma criação explícita e idempotente-por-nome (nunca
     * sobrescreve um Perfil já existente; chamar 2x cria 2 Perfis
     * distintos, exatamente como "Novo Perfil"/"Duplicar" já fazem hoje —
     * cabe ao administrador renomear se quiser evitar duplicidade).
     */
    public static function criar(Tenant $tenant, string $chave, ?string $nomeCustom = null): Perfil
    {
        $definicao = self::definicao($chave);

        if ($definicao === null) {
            throw new \InvalidArgumentException("Template especialista desconhecido: {$chave}");
        }

        return Perfil::criarComCapacidades(
            $tenant,
            $nomeCustom ?? $definicao['nome'],
            'especialista_'.$chave,
            $definicao['verGatedExtra'],
            $definicao['capacidades'],
            $definicao['verLivrePorPadrao'] ?? true,
            $definicao['descricao'] ?? null,
        );
    }
}
