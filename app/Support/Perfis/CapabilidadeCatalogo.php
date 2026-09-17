<?php

namespace App\Support\Perfis;

use App\Models\Perfil;
use App\Support\CatalogoFuncionalidades;

/**
 * FASE 2C — camada de APRESENTAÇÃO pura sobre o catálogo de autorização
 * já existente (`App\Support\CatalogoFuncionalidades` / `Perfil::
 * REGRAS_ESCRITA`). Nunca é uma segunda fonte de autoridade (Seção 12 do
 * pedido: "preset é apenas UX") — toda decisão aqui é derivada de
 * `Perfil::acoesReaisPara()`, nunca uma lista de capabilities inventada
 * por slug. Nenhuma Policy consulta esta classe.
 */
class CapabilidadeCatalogo
{
    /**
     * Rótulo humano de cada ação — nunca expor a chave técnica (Seção 16:
     * nunca mostrar "liberar_para_construcao"/"restricoes.quadro|resolver"
     * cru ao administrador).
     */
    public const LABELS_ACAO = [
        'ver' => 'Visualizar',
        'criar' => 'Criar',
        'editar' => 'Editar',
        'excluir' => 'Excluir',
        'comentar' => 'Comentar',
        'resolver' => 'Resolver',
        'reabrir' => 'Reabrir',
        'liberar_para_construcao' => 'Liberar para construção',
    ];

    /**
     * Ajuda curta por ação (Seção 17) — genérica quando o significado é
     * consistente entre funcionalidades (ex.: "comentar" sempre significa
     * a mesma coisa), específica quando a ação só existe numa única
     * funcionalidade do catálogo hoje. Nunca jargão técnico.
     */
    public const AJUDA_ACAO = [
        'ver' => 'Permite consultar esta funcionalidade.',
        'criar' => 'Permite cadastrar novos registros.',
        'editar' => 'Permite alterar registros já existentes.',
        'excluir' => 'Permite excluir registros.',
        'comentar' => 'Permite participar por comentários, sem alterar o registro.',
        'resolver' => 'Permite alterar uma restrição para Resolvida.',
        'reabrir' => 'Permite reabrir uma restrição já resolvida.',
        'liberar_para_construcao' => 'Permite registrar ou revogar a liberação formal de uma revisão para construção.',
    ];

    /**
     * FASE 2C — fechamento adversarial, Seção 15/26: ações de AUTORIDADE
     * FORMAL ELEVADA que nunca deveriam ser concedidas silenciosamente
     * "de brinde" só porque o administrador escolheu o preset "Operação"
     * pra uma funcionalidade (ex.: "Operação" em Engenharia não significa
     * automaticamente "pode Liberar para Construção" — são decisões de
     * autoridade distintas, mesmo as duas exigindo hoje o mesmo Papel
     * mínimo em `Perfil::REGRAS_ESCRITA`). Lista pequena e explícita
     * (nunca inferida por heurística de nome) — hoje só
     * `liberar_para_construcao` se qualifica: é a única ação do catálogo
     * documentada como um ato formal de controle/qualidade, distinto de
     * criar/editar rotineiros (ver docblock de `engenharia.pacotes` em
     * `Perfil::REGRAS_ESCRITA`). Excluídas do preset "Operação"
     * (`presetsPara()`) — só aparecem via "Gestão completa" (quando
     * `excluir` também é real pra essa funcionalidade) ou marcando a
     * ação manualmente no Modo Avançado, nunca escondidas: o preset
     * "Operação" nunca inclui ação alguma que não esteja listada nele.
     *
     * `resolver`/`reabrir` (Restrições) foram avaliados e DELIBERADAMENTE
     * mantidos fora desta lista — mesmo Papel mínimo de `editar` pra essa
     * funcionalidade (não um degrau de autoridade à parte como
     * `liberar_para_construcao`, que é uma ação de controle documental
     * formal, não uma ação operacional do dia a dia de quem já edita
     * restrições).
     */
    private const ACOES_ELEVADAS = ['liberar_para_construcao'];

    /**
     * Domínio de negócio (Seção 10) — agrupamento pensado pro Modo
     * Simples, INDEPENDENTE de `CatalogoFuncionalidades::porSecao()`
     * (que continua servindo só a matriz legada) — reflete os domínios
     * reais do produto, confirmados em `resources/menu/verticalMenu.json`
     * e no próprio catálogo (Seção 10: "usar domínios reais encontrados",
     * nunca inventar um módulo/domínio sem funcionalidade real por trás —
     * por isso não existe aqui um domínio "Administração": nenhuma
     * funcionalidade do catálogo mapeia pra ele hoje, e
     * `funcionalidadesPorDominio()` simplesmente nunca o preenche).
     */
    private const DOMINIOS = [
        'Home / Gestão' => ['dashboard.gerencial', 'gestao.cockpit', 'gestao.suprimentos', 'gestao.engenharia', 'obras.minhas_obras', 'gestao.benchmarking'],
        'Planejamento' => ['obras.importar_cronograma', 'obras.linhas_base', 'obras.curvas', 'planejamento.requisicoes'],
        'Radar / Prontidão' => ['restricoes.lookahead', 'restricoes.central_prontidao'],
        'Restrições' => ['restricoes.quadro', 'restricoes.plano_semanal', 'restricoes.minhas_programacoes', 'restricoes.causas', 'restricoes.matriz', 'restricoes.relatorios', 'restricoes.plano_acao'],
        'Relatórios' => ['report.relatorios', 'report.importar_avanco'],
        'Engenharia' => ['engenharia.pacotes'],
        'Suprimentos' => ['suprimentos.mapa'],
        'Estoque' => ['estoque.movimentacao', 'estoque.reserva', 'estoque.conciliacao', 'estoque.inventario'],
        'Industrialização' => ['estoque.industrializacao'],
        'Lições Aprendidas' => ['gestao.licoes-aprendidas'],
        'Cadastros' => [
            'cadastros.clientes', 'cadastros.obras', 'cadastros.categorias_restricao',
            'cadastros.itens_prontidao', 'cadastros.convite_config', 'cadastros.fornecedores',
            'cadastros.feriados', 'cadastros.fluxos_suprimento', 'cadastros.status_documentos',
            'cadastros.unidades_medida', 'cadastros.familias_material',
        ],
    ];

    public static function nomeAcao(string $acao): string
    {
        return self::LABELS_ACAO[$acao] ?? ucfirst(str_replace('_', ' ', $acao));
    }

    public static function ajudaAcao(string $acao): ?string
    {
        return self::AJUDA_ACAO[$acao] ?? null;
    }

    /**
     * @return array<string, array<int, array{slug: string, nome: string, secao: string, escopo: string}>>
     */
    public static function funcionalidadesPorDominio(): array
    {
        $todas = collect(CatalogoFuncionalidades::todas())->keyBy('slug');
        $agrupado = [];

        foreach (self::DOMINIOS as $dominio => $slugs) {
            foreach ($slugs as $slug) {
                if ($todas->has($slug)) {
                    $agrupado[$dominio][] = $todas[$slug];
                }
            }
        }

        return $agrupado;
    }

    /**
     * Presets do Modo Simples (Seção 11/12) — SEMPRE derivados de
     * `Perfil::acoesReaisPara()`, nunca uma lista hardcoded por slug.
     * Cada preset é só um ATALHO pra um conjunto de ações reais — a
     * autoridade nunca deixa de ser `PerfilPermissao` (Seção 12: "preset
     * não é nova autoridade", nenhuma coluna `nivel_acesso` é criada).
     *
     * Regra genérica (nunca aplicada cegamente — Seção 11): "Consulta"
     * só existe quando a funcionalidade tem 'ver' real (sempre tem);
     * "Consulta + Comentários" só existe quando 'comentar' é uma ação
     * real desta funcionalidade (Seção 15 — recurso sem comentário nunca
     * mostra este preset); "Operação" agrupa toda ação real que não seja
     * ver/comentar/excluir/ELEVADA (Seção 15 — nunca inclui
     * `liberar_para_construcao` "de brinde"); "Gestão completa" só
     * aparece como preset DISTINTO quando 'excluir' OU alguma ação
     * elevada é real (senão coincidiria com Operação, uma opção
     * redundante que confundiria o administrador) — é o único preset
     * (fora do Modo Avançado) que concede uma ação elevada, sempre
     * explicitamente rotulado como "completa".
     *
     * @return array<int, array{chave: string, nome: string, acoes: array<int, string>}>
     */
    public static function presetsPara(string $funcionalidade): array
    {
        $reais = Perfil::acoesReaisPara($funcionalidade);
        $elevadas = self::acoesElevadasReais($funcionalidade);
        $presets = [];

        $presets[] = ['chave' => 'sem_acesso', 'nome' => 'Sem acesso', 'acoes' => []];

        if (in_array('ver', $reais, true)) {
            $presets[] = ['chave' => 'consulta', 'nome' => 'Consulta', 'acoes' => ['ver']];
        }

        if (in_array('comentar', $reais, true)) {
            $presets[] = ['chave' => 'colaboracao', 'nome' => 'Consulta + Comentários', 'acoes' => ['ver', 'comentar']];
        }

        $operacionais = array_values(array_diff($reais, ['ver', 'comentar', 'excluir', ...$elevadas]));
        if ($operacionais !== []) {
            $presets[] = [
                'chave' => 'operacao',
                'nome' => 'Operação',
                'acoes' => array_values(array_unique([...$operacionais, 'ver'])),
            ];
        }

        if (in_array('excluir', $reais, true) || $elevadas !== []) {
            $presets[] = ['chave' => 'gestao', 'nome' => 'Gestão completa', 'acoes' => $reais];
        }

        return $presets;
    }

    /**
     * Ações elevadas (`ACOES_ELEVADAS`) que são reais pra esta
     * funcionalidade — usado pela UI pra avisar, quando o preset
     * "Operação" está ativo, que a autoridade formal correspondente
     * NÃO foi concedida por ele (Seção 15/26: "manter separadas no modo
     * avançado" — nunca escondido, sempre uma frase explícita).
     *
     * @return array<int, string>
     */
    public static function acoesElevadasReais(string $funcionalidade): array
    {
        return array_values(array_intersect(Perfil::acoesReaisPara($funcionalidade), self::ACOES_ELEVADAS));
    }

    /**
     * Qual preset (se algum) representa EXATAMENTE o conjunto de ações
     * ativas — usado pra destacar o preset atual na UI (Modo Simples).
     * `null` quando a combinação é "personalizada" (não bate com nenhum
     * preset) — a UI então indica isso e direciona pro Modo Avançado,
     * nunca finge que existe um preset que não existe.
     *
     * @param  array<int, string>  $acoesAtivas
     */
    public static function presetAtivo(string $funcionalidade, array $acoesAtivas): ?string
    {
        sort($acoesAtivas);

        foreach (self::presetsPara($funcionalidade) as $preset) {
            $comparar = $preset['acoes'];
            sort($comparar);
            if ($comparar === $acoesAtivas) {
                return $preset['chave'];
            }
        }

        return null;
    }
}
