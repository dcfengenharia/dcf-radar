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
        'slug_padrao',
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
        'restricoes.lookahead' => ['criar' => Papel::Engenheiro, 'editar' => Papel::Encarregado, 'excluir' => Papel::GerentePlanejamento],
        'restricoes.quadro' => ['criar' => Papel::Encarregado, 'editar' => Papel::Engenheiro, 'excluir' => Papel::GerentePlanejamento],
        'restricoes.plano_semanal' => ['editar' => Papel::Encarregado],
        'restricoes.minhas_programacoes' => ['editar' => Papel::Encarregado],
        'restricoes.causas' => ['criar' => Papel::Encarregado, 'editar' => Papel::Encarregado],
        'restricoes.matriz' => ['editar' => Papel::Engenheiro],
        // Fase 4.2 (Plano de Ação) — mesmo limiar de restricoes.quadro
        // (Quadro de Restrições), a página irmã mais próxima em espírito
        // (item de workflow com responsável/prazo/status).
        'restricoes.plano_acao' => ['criar' => Papel::Encarregado, 'editar' => Papel::Engenheiro, 'excluir' => Papel::GerentePlanejamento],
        'report.relatorios' => ['criar' => Papel::GerentePlanejamento, 'editar' => Papel::GerentePlanejamento, 'excluir' => Papel::GerentePlanejamento],
        'report.importar_avanco' => ['criar' => Papel::GerentePlanejamento, 'editar' => Papel::GerentePlanejamento, 'excluir' => Papel::GerentePlanejamento],
        'suprimentos.mapa' => ['criar' => Papel::Encarregado, 'editar' => Papel::Engenheiro, 'excluir' => Papel::GerentePlanejamento],
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
        'engenharia.pacotes' => ['criar' => Papel::Admin, 'editar' => Papel::Admin, 'excluir' => Papel::Admin],
    ];

    public function permissoes(): HasMany
    {
        return $this->hasMany(PerfilPermissao::class);
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
                    'slug_padrao' => $papel->value,
                ]);

                foreach (CatalogoFuncionalidades::slugs() as $slug) {
                    PerfilPermissao::create([
                        'tenant_id' => $tenant->id,
                        'perfil_id' => $perfil->id,
                        'funcionalidade' => $slug,
                        'acao' => 'ver',
                    ]);
                }

                foreach (self::REGRAS_ESCRITA as $slug => $acoes) {
                    foreach ($acoes as $acao => $minimo) {
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
