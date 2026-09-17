<?php

namespace App\Models;

use App\Enums\Papel;
use App\Models\Concerns\BelongsToTenant;
use App\Support\CatalogoFuncionalidades;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Perfil extends Model
{
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $table = 'perfis';

    protected $fillable = [
        'tenant_id',
        'nome',
        'descricao',
        'slug_padrao',
    ];

    /**
     * FASE 2C, Seção 4/5 — descrição curta e humana dos 5 perfis padrão
     * legados, usada por `seedPadrao()` pra preencher `descricao` (nunca
     * consultada por nenhuma Policy — é só apresentação). Perfil
     * personalizado nunca ganha um destes textos.
     */
    private const DESCRICOES_PADRAO = [
        'admin' => 'Administra a empresa: perfis, acessos e todas as áreas do sistema.',
        'gerente_planejamento' => 'Cronograma, linhas de base, curvas e o planejamento da obra de ponta a ponta.',
        'engenheiro' => 'Restrições, engenharia e prontidão — apoio técnico à execução da obra.',
        'encarregado' => 'Operação de campo: restrições, prontidão e apontamentos do dia a dia.',
        'cliente_leitura' => 'Acesso somente leitura, pensado para o cliente acompanhar a obra.',
    ];

    /**
     * Papel mínimo (do enum legado) exigido pra cada ação de escrita em
     * cada funcionalidade — usado só pra reproduzir, célula a célula, o
     * comportamento atual das 8 Policies ao semear os 5 perfis padrão de
     * um tenant novo. "ver" não entra aqui porque é liberado pra todo
     * papel em toda funcionalidade (ninguém é bloqueado de visualizar
     * hoje). Funcionalidades sem Policy própria hoje (ex.:
     * obras.importar_cronograma, cadastros.*) recebem um limiar por
     * analogia com a página irmã mais próxima — não têm enforcement real
     * ainda, só preenchem a matriz de forma consistente.
     */
    private const REGRAS_ESCRITA = [
        'obras.minhas_obras' => ['editar' => Papel::GerentePlanejamento, 'excluir' => Papel::Admin],
        'obras.importar_cronograma' => ['criar' => Papel::GerentePlanejamento, 'editar' => Papel::GerentePlanejamento, 'excluir' => Papel::GerentePlanejamento],
        'obras.linhas_base' => ['criar' => Papel::GerentePlanejamento, 'editar' => Papel::GerentePlanejamento, 'excluir' => Papel::GerentePlanejamento],
        'obras.curvas' => ['editar' => Papel::GerentePlanejamento, 'excluir' => Papel::GerentePlanejamento],
        // Fase 2B, Seção 12/14 — 'comentar' capacidade própria, mesmo
        // limiar de quem hoje CONSEGUE comentar (AtividadePolicy::comentar()
        // usava 'editar' antes desta fase — nunca 'criar', que é um limiar
        // mais alto e teria REDUZIDO quem consegue comentar).
        'restricoes.lookahead' => ['criar' => Papel::Engenheiro, 'editar' => Papel::Encarregado, 'comentar' => Papel::Encarregado, 'excluir' => Papel::GerentePlanejamento],
        // Fase 2B — 'comentar' no mesmo limiar de 'criar' (RestricaoPolicy::
        // comentar() usava 'criar' antes desta fase); 'resolver'/'reabrir'
        // no mesmo limiar de 'editar' (RestricaoPolicy::resolver()/
        // reabrir() usavam o fallback 'editar' antes desta fase — o atalho
        // de responsável próprio da Restrição continua intocado nas duas
        // Policies, independente de Perfil).
        'restricoes.quadro' => ['criar' => Papel::Encarregado, 'editar' => Papel::Engenheiro, 'comentar' => Papel::Encarregado, 'resolver' => Papel::Engenheiro, 'reabrir' => Papel::Engenheiro, 'excluir' => Papel::GerentePlanejamento],
        'restricoes.plano_semanal' => ['editar' => Papel::Encarregado],
        'restricoes.minhas_programacoes' => ['editar' => Papel::Encarregado],
        'restricoes.causas' => ['criar' => Papel::Encarregado, 'editar' => Papel::Encarregado],
        'restricoes.matriz' => ['editar' => Papel::Engenheiro],
        // Fase 4.2 (Plano de Ação) — mesmo limiar de restricoes.quadro
        // (Quadro de Restrições), a página irmã mais próxima em espírito
        // (item de workflow com responsável/prazo/status).
        'restricoes.plano_acao' => ['criar' => Papel::Encarregado, 'editar' => Papel::Engenheiro, 'excluir' => Papel::GerentePlanejamento],
        // Fase 2B — 'comentar' no mesmo limiar (livre — ClienteLeitura é o
        // nível mínimo, "todo Papel qualifica") de 'ver', que já era o
        // limiar usado por ReportPolicy::comentar() antes desta fase —
        // comentar um Report emitido já era independente de 'editar'
        // desde a Fase de Report Semanal; esta chave só formaliza isso
        // como capacidade própria, sem mudar o alcance de quem consegue.
        'report.relatorios' => ['criar' => Papel::GerentePlanejamento, 'editar' => Papel::GerentePlanejamento, 'comentar' => Papel::ClienteLeitura, 'excluir' => Papel::GerentePlanejamento],
        'report.importar_avanco' => ['criar' => Papel::GerentePlanejamento, 'editar' => Papel::GerentePlanejamento, 'excluir' => Papel::GerentePlanejamento],
        // Fase 2B — 'comentar' no mesmo limiar de 'editar'
        // (⚡suprimentos.blade.php::adicionarComentario() usava
        // garantirPermissao('editar') antes desta fase).
        'suprimentos.mapa' => ['criar' => Papel::Encarregado, 'editar' => Papel::Engenheiro, 'comentar' => Papel::Engenheiro, 'excluir' => Papel::GerentePlanejamento],
        // Ciclo 19, Etapa 19.2 — só 'editar' (decisão do usuário: sem
        // granularidade de criar/excluir/emitir separada). "Emitida
        // exclusivamente pelo setor de Planejamento" — mesmo limiar de
        // obras.importar_cronograma/linhas_base/curvas, todas também
        // responsabilidade do Planejamento.
        'planejamento.requisicoes' => ['editar' => Papel::GerentePlanejamento],
        // Ciclo 20, Etapa 20.1 — mesmo limiar de suprimentos.mapa (página
        // irmã mais próxima em espírito: operação de campo registrando um
        // fato quantitativo sobre a mesma cadeia de suprimentos). Sem
        // Papel legado dedicado a "Almoxarifado" — Encarregado é o nível
        // operacional mais próximo já usado pra ações de campo no
        // catálogo.
        'estoque.movimentacao' => ['criar' => Papel::Encarregado, 'editar' => Papel::Engenheiro, 'excluir' => Papel::GerentePlanejamento],
        // Ciclo 20, Etapa 20.2 — mesmo limiar de planejamento.requisicoes
        // (só 'editar', cobrindo criar/alterar/remover/reservar/liberar):
        // Destinação Planejada é uma decisão de como a demanda formal se
        // reparte entre Frentes — mesma seniority de decidir uma RP.
        // Reserva (física) nasce sob o MESMO slug por decisão do pedido
        // (Seção 36, "Planejamento/Reserva" é um único guarda-chuva) —
        // limiar revisável no futuro se o uso operacional do dia a dia
        // mostrar que reservar fisicamente pede um nível mais baixo que
        // planejar a divisão por Frente.
        'estoque.reserva' => ['editar' => Papel::GerentePlanejamento],
        // Ciclo 20, Etapa 20.4 — decisão do usuário: Encarregado
        // para criar/editar a Aplicação/Conciliação — é uma
        // confirmação operacional de campo ("onde foi aplicado"),
        // mesma natureza de quem já registra a Saída física. Slug
        // PRÓPRIO, nunca reaproveita estoque.movimentacao
        // (Almoxarifado registra) nem estoque.reserva (Planejamento
        // decide destinação) — as 3 responsabilidades ficam
        // deliberadamente separadas (Seção 40 do pedido). Sem
        // workflow de aprovação nesta etapa. "excluir" bumped pra
        // GerentePlanejamento (nunca Encarregado, mesmo padrão de
        // estoque.movimentacao) — achado real de regressão: o
        // catálogo tem uma invariante já testada
        // (MigracaoPerfisPadraoTest::
        // test_encarregado_nao_tem_nenhuma_permissao_de_excluir)
        // de que Encarregado NUNCA recebe "excluir" em nenhum slug
        // do catálogo — a decisão do usuário só cobria criar/editar,
        // excluir=Encarregado teria sido uma extrapolação minha
        // que quebrava essa invariante.
        'estoque.conciliacao' => ['criar' => Papel::Encarregado, 'editar' => Papel::Encarregado, 'excluir' => Papel::GerentePlanejamento],
        // Ciclo 20, Etapa 20.5 — slug PRÓPRIO, decisão do usuário
        // (Seção 41/42): criar=Encarregado (registrar remessa/
        // retorno/produção/entrega físicos, mesmo nível de
        // estoque.movimentacao/estoque.conciliacao), editar=
        // Engenheiro (criar Ordem, vincular Documento de fabricação,
        // declarar Produtos previstos), excluir=GerentePlanejamento
        // (nunca Encarregado — mesma invariante já corrigida em
        // 20.4: MigracaoPerfisPadraoTest::
        // test_encarregado_nao_tem_nenhuma_permissao_de_excluir).
        'estoque.industrializacao' => ['criar' => Papel::Encarregado, 'editar' => Papel::Engenheiro, 'excluir' => Papel::GerentePlanejamento],
        // Ciclo 20, Etapa 20.7 — criar=Encarregado (abre o Inventário e
        // registra contagens/recontagens), editar=Engenheiro (move
        // Em Contagem→Em Análise, conclui, e é UMA das duas permissões
        // exigidas pra aprovar Ajuste — a outra é
        // estoque.movimentacao|editar, checada em conjunto no Livewire,
        // nunca dentro da Action), excluir=GerentePlanejamento (cancela
        // — nunca Encarregado, mesma invariante de sempre).
        'estoque.inventario' => ['criar' => Papel::Encarregado, 'editar' => Papel::Engenheiro, 'excluir' => Papel::GerentePlanejamento],
        'cadastros.clientes' => ['criar' => Papel::Admin, 'editar' => Papel::Admin, 'excluir' => Papel::Admin],
        'cadastros.obras' => ['criar' => Papel::Admin, 'editar' => Papel::Admin, 'excluir' => Papel::Admin],
        'cadastros.categorias_restricao' => ['criar' => Papel::Admin, 'editar' => Papel::Admin, 'excluir' => Papel::Admin],
        'cadastros.itens_prontidao' => ['criar' => Papel::Admin, 'editar' => Papel::Admin, 'excluir' => Papel::Admin],
        'cadastros.convite_config' => ['criar' => Papel::Admin, 'editar' => Papel::Admin, 'excluir' => Papel::Admin],
        'cadastros.fornecedores' => ['criar' => Papel::Admin, 'editar' => Papel::Admin, 'excluir' => Papel::Admin],
        'cadastros.feriados' => ['criar' => Papel::Admin, 'editar' => Papel::Admin, 'excluir' => Papel::Admin],
        'cadastros.fluxos_suprimento' => ['criar' => Papel::Admin, 'editar' => Papel::Admin, 'excluir' => Papel::Admin],
        'cadastros.status_documentos' => ['criar' => Papel::Admin, 'editar' => Papel::Admin, 'excluir' => Papel::Admin],
        // Ajuste de arquitetura de navegação — reposicionados de
        // 'estoque.movimentacao' (criar=Encarregado) pra Configurações →
        // Cadastros: reaproveita o MESMO tier Admin de todo cadastro
        // corporativo irmão acima (nenhuma exceção existia), por coerência
        // com o padrão do catálogo — nunca uma estrutura nova inventada.
        // Consequência real de segurança: quem só tinha 'estoque.
        // movimentacao' (Encarregado/Engenheiro) deixa de conseguir criar/
        // editar/inativar Unidade ou Família — só Admin do tenant.
        'cadastros.unidades_medida' => ['criar' => Papel::Admin, 'editar' => Papel::Admin, 'excluir' => Papel::Admin],
        'cadastros.familias_material' => ['criar' => Papel::Admin, 'editar' => Papel::Admin, 'excluir' => Papel::Admin],
        // Fase 2B, Seção 18 — 'liberar_para_construcao' capacidade própria,
        // mesmo limiar de 'editar' (o único caller real,
        // ⚡documentos-engenharia.blade.php::liberarRevisaoVigente()/
        // revogarLiberacaoRevisaoVigente(), usava garantirPermissaoNaObraAtual
        // ('editar') antes desta fase).
        'engenharia.pacotes' => ['criar' => Papel::Admin, 'editar' => Papel::Admin, 'liberar_para_construcao' => Papel::Admin, 'excluir' => Papel::Admin],
        // Ciclo 21, Etapa 21.5 — ÚNICO slug do catálogo cujo 'ver' NÃO é
        // aberto por padrão (Seção 34 do pedido: "não conceder
        // automaticamente pra todo usuário da obra"). `seedPadrao()`
        // (abaixo) trata a chave 'ver' aqui como um MÍNIMO, nunca mais
        // "grátis pra todo mundo" — GerentePlanejamento é o nível mínimo
        // (Gerente de Obra/Projeto, a persona do pedido); Admin (nível 5)
        // sempre passa por já ser >= GerentePlanejamento (nível 4).
        // Sem 'criar'/'editar'/'excluir' — o Cockpit é 100% somente-leitura.
        'gestao.cockpit' => ['ver' => Papel::GerentePlanejamento],
        // Ciclo 21, Etapa 21.6 — Cockpit de Suprimentos e Abastecimento.
        // Mesmo mecanismo/limiar do Cockpit Executivo (Seção 31 do pedido) —
        // 100% somente-leitura, sem criar/editar/excluir.
        'gestao.suprimentos' => ['ver' => Papel::GerentePlanejamento],
        // Ciclo 22, Etapa 22.2 — Cockpit de Engenharia e Liberação para
        // Construção. Mesmo mecanismo/limiar dos 2 Cockpits irmãos — 100%
        // somente-leitura, decisão gerencial (Seção 1/25 do pedido: perfis
        // reais lidos em `App\Enums\Papel`, não assumidos — Engenheiro
        // sozinho NÃO é o mínimo aqui, pela mesma razão de sempre: é uma
        // tela de decisão gerencial de MÚLTIPLOS domínios, não uma tela
        // operacional do GED).
        'gestao.engenharia' => ['ver' => Papel::GerentePlanejamento],
        // Ciclo 23, Etapa 23.1 — Lições Aprendidas. Mapa aprovado pelo
        // usuário: 'criar' (Encarregado, autor de campo) < 'editar'
        // (Engenheiro, edita+envia pra validação) < 'excluir'
        // (GerentePlanejamento, cobre publicar/arquivar/devolver pra
        // rascunho — as 3 transições de governança — e a exclusão de
        // rascunho/em-validação). Mesmo padrão de 3 tiers já usado em
        // 'suprimentos.mapa'/'estoque.inventario'. Sem 'ver' aqui — fica
        // aberto por padrão a todo perfil vinculado à obra, como a
        // maioria do catálogo.
        'gestao.licoes-aprendidas' => ['criar' => Papel::Encarregado, 'editar' => Papel::Engenheiro, 'excluir' => Papel::GerentePlanejamento],
    ];

    public function permissoes(): HasMany
    {
        return $this->hasMany(PerfilPermissao::class);
    }

    /**
     * FASE 2C, Seção 5 — "Perfil padrão DCF.ENG" (veio do seed automático
     * do tenant, `slug_padrao` não-nulo) versus "Perfil personalizado"
     * (criado manualmente, `slug_padrao` sempre nulo). `slug_padrao` é uma
     * coluna string livre (nunca restrita aos 5 valores de `Papel::cases()`
     * no schema) — os novos templates especialistas (Suprimentos/
     * Almoxarifado/Executivo/Planejamento, ver `App\Support\Perfis\
     * TemplatesEspecialistas`) usam slugs próprios sem precisar virar um
     * novo `Papel` (que modelaria uma hierarquia LINEAR de nível, incompatível
     * com papéis "laterais"/especialistas que têm autoridade alta num
     * domínio e nenhuma em outro).
     */
    public function ehPadrao(): bool
    {
        return $this->slug_padrao !== null;
    }

    /**
     * FASE 2C, Seção 13 — acesso público e somente-leitura às chaves de
     * `REGRAS_ESCRITA` pra uma funcionalidade, SEM duplicar a regra de
     * autoridade em nenhum outro lugar (a UI só passa a saber "quais ações
     * têm significado real aqui", nunca decide ela mesma o que é real).
     * `CatalogoFuncionalidades::ACOES` (ver/criar/editar/excluir) continua
     * existindo pra manter a matriz LEGADA funcionando sem alteração — este
     * método é só pra apresentação nova (Modo Avançado/Simples), nunca
     * consultado por nenhuma Policy.
     *
     * @return array<int, string>
     */
    public static function acoesReaisPara(string $funcionalidade): array
    {
        return array_values(array_unique([
            'ver',
            ...array_keys(self::REGRAS_ESCRITA[$funcionalidade] ?? []),
        ]));
    }

    /**
     * Slugs cujo 'ver' NÃO é liberado por padrão (Ciclo 21.5) — os únicos
     * 3 Cockpits hoje. Derivado de `REGRAS_ESCRITA` (nunca uma segunda
     * lista hardcoded) — o marcador já usado por `seedPadrao()` é
     * literalmente "existe uma chave 'ver' nesta funcionalidade".
     *
     * @return array<int, string>
     */
    public static function slugsComVerGated(): array
    {
        return array_keys(array_filter(
            self::REGRAS_ESCRITA,
            fn (array $acoes) => array_key_exists('ver', $acoes)
        ));
    }

    /**
     * FASE 2C — cria um Perfil personalizado (ou template especialista,
     * quando `$slugPadrao` é informado) concedendo 'ver' em TODA
     * funcionalidade do catálogo, EXCETO as gated (Ciclo 21.5), que só
     * recebem 'ver' se explicitamente listadas em `$verGatedExtra` —
     * generaliza pra QUALQUER perfil novo a mesma política "ver livre por
     * padrão, exceto Cockpits" que `seedPadrao()` já aplica aos 5 padrões
     * legados (Seção 32 da Fase 2B original: "isolar a lógica e
     * documentá-la", nunca duplicá-la silenciosamente). Depois grava as
     * capacidades extras (`$capacidadesExtras`, pares [funcionalidade,
     * acao] além de 'ver').
     *
     * NUNCA chamada automaticamente pra tenants existentes — só quando o
     * administrador explicitamente escolhe "Novo Perfil → baseado em
     * template" (Seção 7/9 do pedido: "não sobrescrever clientes
     * existentes").
     *
     * `$verLivrePorPadrao = false` inverte a política pro modo ALLOWLIST
     * (Seção 33, template Executivo): 'ver' só é concedido nos slugs
     * listados em `$verGatedExtra` (que nesse modo deixa de significar só
     * "gated extra" e passa a ser a lista COMPLETA de onde 'ver' é
     * concedido) — nunca herda a franquia "livre em tudo, exceto
     * Cockpits" que os demais perfis (padrão ou personalizados) recebem.
     *
     * @param  array<int, string>  $verGatedExtra
     * @param  array<int, array{0: string, 1: string}>  $capacidadesExtras
     */
    public static function criarComCapacidades(
        Tenant $tenant,
        string $nome,
        ?string $slugPadrao,
        array $verGatedExtra,
        array $capacidadesExtras,
        bool $verLivrePorPadrao = true,
        ?string $descricao = null
    ): self {
        return TenantContext::actingAs($tenant, function () use ($tenant, $nome, $slugPadrao, $verGatedExtra, $capacidadesExtras, $verLivrePorPadrao, $descricao) {
            $perfil = self::create([
                'tenant_id' => $tenant->id,
                'nome' => $nome,
                'descricao' => $descricao,
                'slug_padrao' => $slugPadrao,
            ]);

            $gated = self::slugsComVerGated();

            foreach (CatalogoFuncionalidades::slugs() as $slug) {
                if ($verLivrePorPadrao) {
                    // Mesma política "ver livre por padrão, exceto Cockpits"
                    // já usada pelos 5 perfis legados (seedPadrao()) — só
                    // um slug gated fica de fora se não pedido explicitamente.
                    if (in_array($slug, $gated, true) && ! in_array($slug, $verGatedExtra, true)) {
                        continue;
                    }
                } else {
                    // Modo ALLOWLIST (Seção 33 — template Executivo): 'ver'
                    // só é concedido nos slugs EXPLICITAMENTE listados,
                    // gated ou não. Nunca herda a franquia de 've r' livre
                    // — é assim que "acesso ao Cockpit não implica acesso
                    // operacional" fica garantido já na criação do Perfil,
                    // sem depender só da redação do Cockpit em tempo de
                    // leitura (App\Support\Gestao\RedacaoOperacionalCockpit).
                    if (! in_array($slug, $verGatedExtra, true)) {
                        continue;
                    }
                }

                PerfilPermissao::create([
                    'tenant_id' => $tenant->id,
                    'perfil_id' => $perfil->id,
                    'funcionalidade' => $slug,
                    'acao' => 'ver',
                ]);
            }

            foreach ($capacidadesExtras as [$funcionalidade, $acao]) {
                if ($acao === 'ver') {
                    continue; // já resolvido acima, nunca duplicar a linha
                }

                PerfilPermissao::firstOrCreate([
                    'tenant_id' => $tenant->id,
                    'perfil_id' => $perfil->id,
                    'funcionalidade' => $funcionalidade,
                    'acao' => $acao,
                ]);
            }

            return $perfil;
        });
    }

    /**
     * FASE 2C, Seção 8 — duplica este Perfil (capabilities atuais) num
     * Perfil PERSONALIZADO novo (nunca herda `slug_padrao` — Seção 8:
     * "slug_padrao protegido, se aplicável"). Nunca copia usuários/obras
     * (a nova pivot nunca é tocada) nem identidade de template.
     */
    public function duplicar(string $novoNome): self
    {
        return TenantContext::actingAs($this->tenant, function () use ($novoNome) {
            $novo = self::create([
                'tenant_id' => $this->tenant_id,
                'nome' => $novoNome,
                'descricao' => $this->descricao,
                'slug_padrao' => null,
            ]);

            foreach ($this->permissoes()->get(['funcionalidade', 'acao']) as $linha) {
                PerfilPermissao::create([
                    'tenant_id' => $this->tenant_id,
                    'perfil_id' => $novo->id,
                    'funcionalidade' => $linha->funcionalidade,
                    'acao' => $linha->acao,
                ]);
            }

            return $novo;
        });
    }

    public function tenant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public static function porSlugPadrao(Tenant $tenant, string $slugPadrao): ?self
    {
        return static::where('tenant_id', $tenant->id)->where('slug_padrao', $slugPadrao)->first();
    }

    /**
     * Cria os 5 perfis padrão (equivalentes aos 5 Papel legados) pra um
     * tenant, com as permissões que reproduzem exatamente o acesso que
     * cada papel tem hoje — ninguém perde acesso na migração pro sistema
     * de perfis dinâmicos.
     */
    public static function seedPadrao(Tenant $tenant): void
    {
        TenantContext::actingAs($tenant, function () use ($tenant) {
            foreach (Papel::cases() as $papel) {
                $perfil = static::create([
                    'tenant_id' => $tenant->id,
                    'nome' => $papel->label(),
                    'descricao' => self::DESCRICOES_PADRAO[$papel->value] ?? null,
                    'slug_padrao' => $papel->value,
                ]);

                foreach (CatalogoFuncionalidades::slugs() as $slug) {
                    // Ciclo 21, Etapa 21.5 — achado real: 'ver' era SEMPRE
                    // concedido a todo Papel pra todo slug, sem exceção —
                    // não existia nenhum mecanismo pra restringir a própria
                    // leitura de uma página (só criar/editar/excluir, via
                    // REGRAS_ESCRITA). O Cockpit Executivo exige exatamente
                    // isso (Seção 34: "não conceder automaticamente"), então
                    // REGRAS_ESCRITA[$slug]['ver'], quando presente, passou a
                    // ser tratado como um MÍNIMO — nunca mais "grátis" pra
                    // esse slug específico. Nenhum outro slug do catálogo
                    // tem essa chave (confirmado acima), então o
                    // comportamento de TODOS os outros 40+ slugs já
                    // existentes fica bit-a-bit idêntico ao de antes desta
                    // etapa — `$minimoVer` é sempre `null` pra eles, e o
                    // `ver` continua concedido incondicionalmente.
                    $minimoVer = self::REGRAS_ESCRITA[$slug]['ver'] ?? null;

                    if ($minimoVer === null || $papel->nivel() >= $minimoVer->nivel()) {
                        PerfilPermissao::create([
                            'tenant_id' => $tenant->id,
                            'perfil_id' => $perfil->id,
                            'funcionalidade' => $slug,
                            'acao' => 'ver',
                        ]);
                    }
                }

                foreach (self::REGRAS_ESCRITA as $slug => $acoes) {
                    foreach ($acoes as $acao => $minimo) {
                        if ($acao === 'ver') {
                            continue; // já resolvido no loop acima
                        }

                        if ($papel->nivel() >= $minimo->nivel()) {
                            PerfilPermissao::create([
                                'tenant_id' => $tenant->id,
                                'perfil_id' => $perfil->id,
                                'funcionalidade' => $slug,
                                'acao' => $acao,
                            ]);
                        }
                    }
                }
            }
        });
    }
}
