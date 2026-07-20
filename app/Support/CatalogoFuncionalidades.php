<?php

namespace App\Support;

/**
 * Catálogo fixo de "páginas" do sistema pra fins de autorização — vive em
 * código (não em tabela) porque só muda quando o código muda, mesma
 * filosofia de resources/menu/verticalMenu.json. O que É dinâmico e
 * editável por tenant é qual Perfil tem qual permissão neste catálogo
 * (ver App\Models\PerfilPermissao).
 *
 * Toda funcionalidade expõe as mesmas 4 ações (ver/criar/editar/excluir)
 * mesmo quando a página não tem CRUD completo (ex.: Curvas S não tem
 * "excluir") — aceito ter checkboxes sem Policy nenhuma consultando
 * aquela ação, em troca de um catálogo uniforme e uma UI de matriz
 * simples (decisão registrada no plano de implementação).
 */
class CatalogoFuncionalidades
{
    public const ACOES = ['ver', 'criar', 'editar', 'excluir'];

    public const ESCOPO_OBRA = 'obra';
    public const ESCOPO_TENANT = 'tenant';

    /**
     * @return array<int, array{slug: string, nome: string, secao: string, escopo: string}>
     */
    public static function todas(): array
    {
        return [
            ['slug' => 'dashboard.gerencial', 'nome' => 'Dashboard', 'secao' => 'Dashboard', 'escopo' => self::ESCOPO_OBRA],

            ['slug' => 'obras.minhas_obras', 'nome' => 'Minhas Obras', 'secao' => 'Obras', 'escopo' => self::ESCOPO_OBRA],
            ['slug' => 'obras.importar_cronograma', 'nome' => 'Importar Cronograma', 'secao' => 'Obras', 'escopo' => self::ESCOPO_OBRA],
            ['slug' => 'obras.linhas_base', 'nome' => 'Linhas de Base', 'secao' => 'Obras', 'escopo' => self::ESCOPO_OBRA],
            ['slug' => 'obras.curvas', 'nome' => 'Curvas S', 'secao' => 'Obras', 'escopo' => self::ESCOPO_OBRA],

            ['slug' => 'restricoes.lookahead', 'nome' => 'Lookahead Lean', 'secao' => 'Restrições', 'escopo' => self::ESCOPO_OBRA],
            ['slug' => 'restricoes.quadro', 'nome' => 'Quadro de Restrições', 'secao' => 'Restrições', 'escopo' => self::ESCOPO_OBRA],
            ['slug' => 'restricoes.plano_semanal', 'nome' => 'Plano Semanal', 'secao' => 'Restrições', 'escopo' => self::ESCOPO_OBRA],
            ['slug' => 'restricoes.causas', 'nome' => 'Causas de Não Cumprimento', 'secao' => 'Restrições', 'escopo' => self::ESCOPO_OBRA],
            ['slug' => 'restricoes.matriz', 'nome' => 'Matriz P×I', 'secao' => 'Restrições', 'escopo' => self::ESCOPO_OBRA],
            ['slug' => 'restricoes.relatorios', 'nome' => 'Relatórios de Restrições', 'secao' => 'Restrições', 'escopo' => self::ESCOPO_OBRA],

            ['slug' => 'report.relatorios', 'nome' => 'Relatórios', 'secao' => 'Report', 'escopo' => self::ESCOPO_OBRA],
            ['slug' => 'report.importar_avanco', 'nome' => 'Importar Avanço', 'secao' => 'Report', 'escopo' => self::ESCOPO_OBRA],

            ['slug' => 'suprimentos.mapa', 'nome' => 'Mapa de Suprimentos', 'secao' => 'Suprimentos', 'escopo' => self::ESCOPO_OBRA],

            ['slug' => 'cadastros.clientes', 'nome' => 'Clientes', 'secao' => 'Cadastros', 'escopo' => self::ESCOPO_TENANT],
            ['slug' => 'cadastros.obras', 'nome' => 'Obras (cadastro)', 'secao' => 'Cadastros', 'escopo' => self::ESCOPO_TENANT],
            ['slug' => 'cadastros.categorias_restricao', 'nome' => 'Tipos de Restrição', 'secao' => 'Cadastros', 'escopo' => self::ESCOPO_TENANT],
            ['slug' => 'cadastros.itens_prontidao', 'nome' => 'Itens de Prontidão', 'secao' => 'Cadastros', 'escopo' => self::ESCOPO_TENANT],
            ['slug' => 'cadastros.convite_config', 'nome' => 'Convite por E-mail', 'secao' => 'Cadastros', 'escopo' => self::ESCOPO_TENANT],
            ['slug' => 'cadastros.fornecedores', 'nome' => 'Fornecedores', 'secao' => 'Cadastros', 'escopo' => self::ESCOPO_TENANT],
            ['slug' => 'cadastros.feriados', 'nome' => 'Feriados', 'secao' => 'Cadastros', 'escopo' => self::ESCOPO_TENANT],
            ['slug' => 'cadastros.fluxos_suprimento', 'nome' => 'Tipos de Fluxo (Suprimentos)', 'secao' => 'Cadastros', 'escopo' => self::ESCOPO_TENANT],
            ['slug' => 'cadastros.status_documentos', 'nome' => 'Status de Documento', 'secao' => 'Cadastros', 'escopo' => self::ESCOPO_TENANT],

            // Página com seletor de obra próprio (mesmo padrão de
            // cadastros.itens_prontidao) — por isso ESCOPO_TENANT mesmo
            // com dados que têm obra_id, e não ESCOPO_OBRA: a checagem
            // de permissão usada é temPermissaoEmAlgumaObraDoTenant(),
            // não a da obra ativa na sessão. O nome mudou pra "Lista de
            // Documentos" (era "Pacotes de Engenharia") mas o slug fica
            // igual — trocar o slug quebraria PerfilPermissao já
            // concedida por string.
            ['slug' => 'engenharia.pacotes', 'nome' => 'Lista de Documentos', 'secao' => 'Engenharia', 'escopo' => self::ESCOPO_TENANT],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function slugs(): array
    {
        return array_column(self::todas(), 'slug');
    }

    public static function escopoDe(string $slug): ?string
    {
        foreach (self::todas() as $item) {
            if ($item['slug'] === $slug) {
                return $item['escopo'];
            }
        }

        return null;
    }

    /**
     * Agrupado por seção, na ordem de exibição da matriz de permissões.
     *
     * @return array<string, array<int, array{slug: string, nome: string, secao: string, escopo: string}>>
     */
    public static function porSecao(): array
    {
        $agrupado = [];

        foreach (self::todas() as $item) {
            $agrupado[$item['secao']][] = $item;
        }

        return $agrupado;
    }

    /**
     * Visibilidade dinâmica do menu por funcionalidade.
     *
     * Itens obra-scoped: fora do contexto de obra (usuário ainda não
     * "entrou" numa obra), o item continua visível como hoje, sem
     * gating nenhum, porque não há uma obra concreta pra checar
     * permissão ainda (evita esconder a própria navegação de entrada).
     *
     * Itens tenant-scoped (Cadastros): checam
     * temPermissaoEmAlgumaObraDoTenant(), que já tem seu próprio
     * fallback de bootstrap (libera se o usuário ainda não tem nenhuma
     * obra_user no tenant — tenant recém-criado, criador ainda sem
     * primeira obra).
     */
    public static function usuarioPodeVer(string $slug): bool
    {
        $user = auth()->user();
        if (! $user) {
            return false;
        }

        if (self::escopoDe($slug) === self::ESCOPO_TENANT) {
            return $user->temPermissaoEmAlgumaObraDoTenant($slug, 'ver');
        }

        $obraId = \App\Support\ObraContext::currentId();
        if (! $obraId) {
            return true;
        }

        return $user->temPermissaoNaObra($obraId, $slug, 'ver');
    }

    /**
     * Pra itens de menu com submenu (ex.: "Cadastros"): se TODOS os
     * filhos com `funcionalidade` ficarem escondidos, o item pai
     * também some — não faz sentido mostrar um dropdown vazio.
     *
     * @param array<int, object> $submenu
     */
    public static function algumSubitemVisivel(array $submenu): bool
    {
        foreach ($submenu as $item) {
            if (! isset($item->funcionalidade) || self::usuarioPodeVer($item->funcionalidade)) {
                return true;
            }
        }

        return false;
    }
}
