<?php

namespace App\Support\Gestao;

use App\Models\User;

/**
 * FASE 2B, Seções 20-23 — camada de APRESENTAÇÃO (nunca de domínio) pros
 * 3 Cockpits (Executivo/Suprimentos/Engenharia). Corrige a divergência
 * identificada na auditoria: até esta fase, o gate de entrada do Cockpit
 * (`gestao.*|ver`) liberava TODOS os detalhes operacionais — fornecedor,
 * código de material, RC, Pedido, documento — sem checar a permissão
 * operacional própria de cada domínio.
 *
 * Novo comportamento (Seção 23): permissão de Cockpit permite ver o
 * PAINEL/AGREGADO (contagens, severidade, descrição textual da situação
 * — Seção 20: "vê risco agregado; vê prontidão; vê contagem de
 * impactos"); detalhe operacional sensível (nome de fornecedor, código
 * de material, número de RC/Pedido, deep-link pra tela operacional)
 * respeita a permissão PRÓPRIA daquele domínio — nunca escondido o card
 * inteiro, só o detalhe/deep-link redigido (Seção 23: "pode redigir ou
 * remover deep-link/detalhe").
 *
 * Mapeamento domínio → funcionalidade operacional reaproveita
 * `TipoSituacaoGerencial::dominio()` (Ciclo 21.2, já usado pro Resumo
 * Executivo e pro Digest) — nunca uma segunda taxonomia paralela.
 */
final class RedacaoOperacionalCockpit
{
    private const FUNCIONALIDADE_POR_DOMINIO = [
        'estoque' => 'estoque.movimentacao',
        'suprimentos' => 'suprimentos.mapa',
        'inventario' => 'estoque.inventario',
        'engenharia' => 'engenharia.pacotes',
        'industrializacao' => 'estoque.industrializacao',
    ];

    /**
     * "$user pode ver o DETALHE operacional das situações/cards deste
     * domínio nesta obra?" — domínio desconhecido nunca é redigido por
     * engano (fail-open só pra domínio sem mapeamento, nunca pra
     * domínio mapeado sem a permissão).
     */
    public static function podeVerDetalheDoDominio(User $user, string $obraId, string $dominio): bool
    {
        $funcionalidade = self::FUNCIONALIDADE_POR_DOMINIO[$dominio] ?? null;

        if ($funcionalidade === null) {
            return true;
        }

        return $user->temPermissaoNaObra($obraId, $funcionalidade, 'ver');
    }

    /**
     * Mesma checagem, resolvida diretamente pelo slug de funcionalidade
     * (pros cards do Cockpit que não vêm de uma `SituacaoGerencial`
     * tipada — ex.: "Pipeline de Suprimentos"/"Fornecedores", que são
     * agregações próprias do Cockpit, sem `TipoSituacaoGerencial`
     * associado).
     */
    public static function podeVerDetalhePorFuncionalidade(User $user, string $obraId, string $funcionalidade): bool
    {
        return $user->temPermissaoNaObra($obraId, $funcionalidade, 'ver');
    }
}
