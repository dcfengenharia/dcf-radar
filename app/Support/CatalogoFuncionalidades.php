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
            // Ciclo 21, Etapa 21.5 — Cockpit Executivo da Obra. Único slug do
            // catálogo cujo 'ver' não é aberto por padrão a todo perfil com
            // vínculo na obra (ver App\Models\Perfil::seedPadrao() — mecanismo
            // novo, `REGRAS_ESCRITA['gestao.cockpit']['ver']`) — decisão
            // explícita do pedido ("não conceder automaticamente pra todo
            // usuário da obra"), já que a página consolida dado gerencial de
            // TODOS os domínios (Suprimentos/Estoque/Engenharia/Industrialização/
            // Inventário/Planejamento) numa visão única pensada pra
            // Gerente de Obra/Projeto.
            ['slug' => 'gestao.cockpit', 'nome' => 'Cockpit Executivo', 'secao' => 'Dashboard', 'escopo' => self::ESCOPO_OBRA],
            // Ciclo 21, Etapa 21.6 — Cockpit de Suprimentos e Abastecimento.
            // Mesmo mecanismo de gate de 'ver' da 21.5 (Seção 31 do pedido:
            // "não assuma que só a equipe de Suprimentos deve visualizar" —
            // mesmo público de decisão do Cockpit Executivo, mesmo limiar).
            ['slug' => 'gestao.suprimentos', 'nome' => 'Cockpit de Suprimentos', 'secao' => 'Dashboard', 'escopo' => self::ESCOPO_OBRA],
            // Ciclo 22, Etapa 22.2 — Cockpit de Engenharia e Liberação para
            // Construção. Mesmo mecanismo/limiar dos 2 Cockpits irmãos (Seção
            // 25 do pedido: "reutilizar o mecanismo `ver` já auditado na
            // 21.6/21.7" — auditoria estendida em `PermissaoVerGateAuditTest`
            // pra cobrir o 3º slug gated).
            ['slug' => 'gestao.engenharia', 'nome' => 'Cockpit de Engenharia', 'secao' => 'Dashboard', 'escopo' => self::ESCOPO_OBRA],

            ['slug' => 'obras.minhas_obras', 'nome' => 'Minhas Obras', 'secao' => 'Obras', 'escopo' => self::ESCOPO_OBRA],
            // Página com seletor múltiplo de obras (compara 2+ ao mesmo
            // tempo) — mesmo motivo de engenharia.pacotes ser
            // ESCOPO_TENANT: não faz sentido travado na obra ativa da
            // sessão.
            ['slug' => 'gestao.benchmarking', 'nome' => 'Benchmarking entre Obras', 'secao' => 'Obras', 'escopo' => self::ESCOPO_TENANT],
            // Ciclo 23, Etapa 23.1 — Memória Operacional Corporativa
            // (Lições Aprendidas). ESCOPO_TENANT: mesma razão de
            // gestao.benchmarking/engenharia.pacotes — a biblioteca
            // corporativa ("Todas as obras") não faz sentido travada na
            // obra ativa da sessão, tem seletor de obra próprio. 'ver'
            // aberto por padrão (nunca gated como os 3 Cockpits) — é uma
            // biblioteca de conhecimento, quanto mais gente consultar
            // melhor. Mapa de ação aprovado pelo usuário: 'criar' cria
            // rascunho, 'editar' edita+envia pra validação, 'excluir'
            // cobre publicar/arquivar/devolver pra rascunho (as 3
            // transições de governança) + exclusão de rascunho/em-
            // validação — mesmo limiar mais alto já usado em toda
            // funcionalidade do catálogo.
            ['slug' => 'gestao.licoes-aprendidas', 'nome' => 'Lições Aprendidas', 'secao' => 'Obras', 'escopo' => self::ESCOPO_TENANT],
            ['slug' => 'obras.importar_cronograma', 'nome' => 'Importar Cronograma', 'secao' => 'Obras', 'escopo' => self::ESCOPO_OBRA],
            ['slug' => 'obras.linhas_base', 'nome' => 'Linhas de Base', 'secao' => 'Obras', 'escopo' => self::ESCOPO_OBRA],
            ['slug' => 'obras.curvas', 'nome' => 'Curvas S', 'secao' => 'Obras', 'escopo' => self::ESCOPO_OBRA],

            ['slug' => 'restricoes.lookahead', 'nome' => 'Lookahead Lean', 'secao' => 'Restrições', 'escopo' => self::ESCOPO_OBRA],
            ['slug' => 'restricoes.quadro', 'nome' => 'Quadro de Restrições', 'secao' => 'Restrições', 'escopo' => self::ESCOPO_OBRA],
            ['slug' => 'restricoes.plano_semanal', 'nome' => 'Plano Semanal', 'secao' => 'Restrições', 'escopo' => self::ESCOPO_OBRA],
            ['slug' => 'restricoes.minhas_programacoes', 'nome' => 'Minhas Programações', 'secao' => 'Restrições', 'escopo' => self::ESCOPO_OBRA],
            ['slug' => 'restricoes.causas', 'nome' => 'Causas de Não Cumprimento', 'secao' => 'Restrições', 'escopo' => self::ESCOPO_OBRA],
            ['slug' => 'restricoes.matriz', 'nome' => 'Matriz P×I', 'secao' => 'Restrições', 'escopo' => self::ESCOPO_OBRA],
            ['slug' => 'restricoes.relatorios', 'nome' => 'Relatórios de Restrições', 'secao' => 'Restrições', 'escopo' => self::ESCOPO_OBRA],
            // Slug independente de 'obras.importar_cronograma' (Fase 4.2,
            // decisão do usuário): gerenciar o Plano de Ação é uma
            // responsabilidade diferente de importar o cronograma em si.
            ['slug' => 'restricoes.plano_acao', 'nome' => 'Plano de Ação', 'secao' => 'Restrições', 'escopo' => self::ESCOPO_OBRA],

            // Ciclo 15 (Etapa B.2) — leitura/triagem consolidada, sem
            // nenhuma ação de escrita própria; só precisa de 'ver' (nunca
            // entra em Perfil::REGRAS_ESCRITA).
            ['slug' => 'restricoes.central_prontidao', 'nome' => 'Central de Prontidão', 'secao' => 'Restrições', 'escopo' => self::ESCOPO_OBRA],

            ['slug' => 'report.relatorios', 'nome' => 'Relatórios', 'secao' => 'Report', 'escopo' => self::ESCOPO_OBRA],
            ['slug' => 'report.importar_avanco', 'nome' => 'Importar Avanço', 'secao' => 'Report', 'escopo' => self::ESCOPO_OBRA],

            ['slug' => 'suprimentos.mapa', 'nome' => 'Mapa de Suprimentos', 'secao' => 'Suprimentos', 'escopo' => self::ESCOPO_OBRA],

            // Ciclo 19, Etapa 19.2 — slug próprio, deliberadamente
            // separado de 'restricoes.*' e 'suprimentos.*' (decisão do
            // usuário): a formalização da Requisição do Planejamento é
            // responsabilidade do Planejamento, não do Quadro de
            // Restrições nem de Suprimentos (que só passa a consumir essa
            // demanda em etapas futuras).
            ['slug' => 'planejamento.requisicoes', 'nome' => 'Requisições do Planejamento', 'secao' => 'Planejamento', 'escopo' => self::ESCOPO_OBRA],

            // Ciclo 20, Etapa 20.1 — decisão do usuário (investigação
            // 20.0, D20): catálogo estoque.* previsto com múltiplos slugs
            // futuros (estoque.reserva/conciliacao/inventario, 20.2+) —
            // nesta etapa só o necessário pra fundação física
            // (Material/LocalEstoque/entrada). 'editar'/'excluir' também
            // cobrem cadastro de Material/Local (decisão tomada durante a
            // implementação: nenhum slug estoque.cadastros foi aprovado
            // separadamente, e a arquitetura já uniforme de 4 ações por
            // funcionalidade comporta os dois usos sem ambiguidade real).
            ['slug' => 'estoque.movimentacao', 'nome' => 'Estoque', 'secao' => 'Estoque', 'escopo' => self::ESCOPO_OBRA],

            // Ciclo 20, Etapa 20.2 — 'estoque.reserva' ativado conforme já
            // previsto acima. Cobre TANTO DestinacaoPlanejadaMaterial
            // (camada lógica — quanto planejar por Frente) QUANTO
            // ReservaEstoque (camada física — quanto comprometer de
            // saldo) — decisão do pedido (Seção 36): as duas nascem juntas
            // sob o guarda-chuva "Planejamento/Reserva", nunca reaproveita
            // 'estoque.movimentacao' (que é sobre entrada física bruta,
            // responsabilidade operacional distinta). 'conciliacao'/
            // 'inventario' continuam reservados pra fases futuras.
            ['slug' => 'estoque.reserva', 'nome' => 'Planejamento / Reservas', 'secao' => 'Estoque', 'escopo' => self::ESCOPO_OBRA],

            // Ciclo 20, Etapa 20.4 — 'estoque.conciliacao' ativado:
            // cobre AplicacaoMaterialEstoque (onde uma Saída física foi
            // efetivamente utilizada) — slug independente de
            // 'estoque.movimentacao'/'estoque.reserva' (Seção 40 do
            // pedido: Almoxarifado registra Saída, Produção/Campo
            // confirma Aplicação, Planejamento só acompanha).
            // 'inventario' continua reservado pra fase futura.
            ['slug' => 'estoque.conciliacao', 'nome' => 'Conciliação / Aplicação', 'secao' => 'Estoque', 'escopo' => self::ESCOPO_OBRA],

            // Ciclo 20, Etapa 20.5 — 'estoque.industrializacao' ativado:
            // cobre OrdemIndustrializacao/RemessaIndustrializacao/
            // ProdutoIndustrializado/genealogia — custódia em terceiro
            // pra fabricação/industrialização externa. Slug
            // independente de 'estoque.movimentacao'/'estoque.reserva'/
            // 'estoque.conciliacao'/'estoque.inventario' (responsabilidades
            // operacionais distintas).
            ['slug' => 'estoque.industrializacao', 'nome' => 'Industrialização em Terceiros', 'secao' => 'Estoque', 'escopo' => self::ESCOPO_OBRA],

            // Ciclo 20, Etapa 20.7 — 'estoque.inventario' ativado: cobre
            // InventarioEstoque/InventarioItem/ContagemInventario/
            // InventarioAjuste. Separado de 'estoque.movimentacao' de
            // propósito (STOP-and-ask, decisão do usuário) — aprovar um
            // Ajuste de Inventário exige DUPLA autorização
            // ('estoque.inventario|editar' E 'estoque.movimentacao|editar'
            // simultaneamente, mesmo padrão já usado em
            // PlanoAcao::transformarEmRestricoes(), Ciclo 11) — quem
            // administra o Inventário não tem, por si só, autoridade sobre
            // o ledger físico que um Ajuste altera.
            ['slug' => 'estoque.inventario', 'nome' => 'Inventário', 'secao' => 'Estoque', 'escopo' => self::ESCOPO_OBRA],

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
