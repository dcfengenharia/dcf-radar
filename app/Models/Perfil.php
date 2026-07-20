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
        'restricoes.causas' => ['criar' => Papel::Encarregado, 'editar' => Papel::Encarregado],
        'restricoes.matriz' => ['editar' => Papel::Engenheiro],
        'report.relatorios' => ['criar' => Papel::GerentePlanejamento, 'editar' => Papel::GerentePlanejamento, 'excluir' => Papel::GerentePlanejamento],
        'report.importar_avanco' => ['criar' => Papel::GerentePlanejamento, 'editar' => Papel::GerentePlanejamento, 'excluir' => Papel::GerentePlanejamento],
        'suprimentos.mapa' => ['criar' => Papel::Encarregado, 'editar' => Papel::Engenheiro, 'excluir' => Papel::GerentePlanejamento],
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
