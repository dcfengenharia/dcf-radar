<?php

use App\Enums\Papel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * BUG TARGETED — "Unidades de Medida"/"Famílias de Materiais" não
 * apareciam no menu real (Configurações → Cadastros), mesmo para o
 * Administrador do tenant, apesar de rota/view/JSON do menu/
 * CatalogoFuncionalidades estarem 100% corretos em runtime.
 *
 * CAUSA RAIZ: `Perfil::seedPadrao()` só roda na CRIAÇÃO do tenant —
 * grava 1 linha em `perfil_permissoes` por (perfil, slug, ação),
 * inclusive `ver` (presença de linha = permissão concedida, NUNCA
 * "aberto por padrão sem linha" — ver `App\Support\
 * CatalogoFuncionalidades::usuarioPodeVer()` /
 * `App\Models\Concerns\HasObraPapel::temPermissaoEmAlgumaObraDoTenant()`).
 * Os slugs `cadastros.unidades_medida`/`cadastros.familias_material`
 * foram adicionados ao catálogo DEPOIS que tenants/perfis já existentes
 * no ambiente (inclusive o próprio Admin usado no teste manual) já
 * tinham sido seedados — nenhuma migration/seeder rodava de novo pra
 * eles, então NENHUM perfil já existente tinha linha em
 * `perfil_permissoes` pra esses 2 slugs, nem `ver`. Por isso os testes
 * automatizados (sempre com tenant NOVO via RefreshDatabase, seedado já
 * com o catálogo atualizado) nunca detectaram o problema — só afeta
 * instalação/tenant PRÉ-EXISTENTE à mudança de catálogo.
 *
 * Backfill EXATAMENTE com a mesma regra de `Perfil::seedPadrao()`: `ver`
 * pra TODO perfil (default ou customizado — nenhum dos 2 slugs declara
 * minimo em `Perfil::REGRAS_ESCRITA['ver']`, mesma regra "grátis" de
 * todo slug fora da família `gestao.cockpit`); `criar`/`editar`/
 * `excluir` só pro perfil cujo `slug_padrao` é `admin` (mesma regra
 * `Papel::Admin` de `Perfil::REGRAS_ESCRITA['cadastros.unidades_medida'
 * |'cadastros.familias_material']`). Perfil totalmente customizado (sem
 * `slug_padrao`, criado via "Perfis de Acesso") só ganha `ver` aqui —
 * escrita fica a critério do Admin do tenant, concedida manualmente na
 * mesma tela (que já lê o catálogo dinamicamente).
 *
 * **Usa `DB::table()` cru em toda leitura/escrita, nunca os models
 * `Perfil`/`PerfilPermissao`** — mesmo padrão já estabelecido no projeto
 * pra operações de plataforma cross-tenant (ex.: `ClienteRelatorioPublicoController`/
 * `MercadoPagoWebhookController`, "resolve o tenant com uma consulta
 * DB::table() mínima"). Achado real durante a construção do teste de
 * regressão desta correção: mesmo com `withoutGlobalScopes()` na
 * LEITURA, `PerfilPermissao::create([...])` ainda dispara
 * `BelongsToTenant::creating()`, que SOBRESCREVE silenciosamente
 * qualquer `tenant_id` explícito sempre que existe um tenant
 * autenticado no momento da chamada (`TenantContext::currentId()`
 * truthy) — em uso real via `php artisan migrate` isso nunca acontece
 * (CLI, sem sessão), mas a migration nunca deveria depender
 * implicitamente disso pra gravar o `tenant_id` certo em cada linha.
 * `DB::table()->insert()` nunca dispara eventos de model — imune a essa
 * classe inteira de problema, independente do contexto de invocação.
 */
return new class extends Migration
{
    private const SLUGS = ['cadastros.unidades_medida', 'cadastros.familias_material'];

    public function up(): void
    {
        DB::table('perfis')->orderBy('id')->chunkById(200, function ($perfis) {
            foreach ($perfis as $perfil) {
                $papel = $perfil->slug_padrao ? Papel::tryFrom($perfil->slug_padrao) : null;

                foreach (self::SLUGS as $slug) {
                    $this->criarSeNaoExiste($perfil->tenant_id, $perfil->id, $slug, 'ver');

                    if ($papel && $papel->podeAoMenos(Papel::Admin)) {
                        foreach (['criar', 'editar', 'excluir'] as $acao) {
                            $this->criarSeNaoExiste($perfil->tenant_id, $perfil->id, $slug, $acao);
                        }
                    }
                }
            }
        });
    }

    private function criarSeNaoExiste(string $tenantId, string $perfilId, string $slug, string $acao): void
    {
        $existe = DB::table('perfil_permissoes')
            ->where('perfil_id', $perfilId)
            ->where('funcionalidade', $slug)
            ->where('acao', $acao)
            ->exists();

        if (! $existe) {
            DB::table('perfil_permissoes')->insert([
                'id' => (string) Str::ulid(),
                'tenant_id' => $tenantId,
                'perfil_id' => $perfilId,
                'funcionalidade' => $slug,
                'acao' => $acao,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('perfil_permissoes')->whereIn('funcionalidade', self::SLUGS)->delete();
    }
};
