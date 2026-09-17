<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FASE 2B — Seções 12-19: introduz capacidades semânticas próprias,
 * SEMPRE copiadas de quem já possui a permissão genérica que hoje
 * autoriza a mesma ação (nunca por threshold de Papel — cobre perfis
 * padrão E perfis 100% customizados uniformemente, cada um com seu
 * próprio conjunto real de PerfilPermissao já concedido).
 *
 * `comentar` (Seção 12/14) — só nos 4 recursos com colaboração humana
 * já auditada (nunca `RestricaoAcao` de baixa/resolver/reabrir, que são
 * ações operacionais, não comentário):
 * - `restricoes.quadro|comentar` ← quem hoje tem `criar` (RestricaoPolicy::
 *   comentar() usava 'criar' antes desta fase).
 * - `restricoes.lookahead|comentar` ← quem hoje tem `editar`
 *   (AtividadePolicy::comentar() usava 'editar' — exemplo literal do
 *   pedido, Seção 14).
 * - `report.relatorios|comentar` ← quem hoje tem `ver` (ReportPolicy::
 *   comentar() já usava 'ver', não 'editar' — comentar um Report já era
 *   independente de editar antes desta fase; a nova chave só formaliza
 *   isso como capacidade própria, sem mudar o alcance).
 * - `suprimentos.mapa|comentar` ← quem hoje tem `editar`
 *   (⚡suprimentos.blade.php::adicionarComentario() usava
 *   garantirPermissao('editar')).
 *
 * Semânticas de alto impacto (Seção 17-19, subconjunto selecionado —
 * "não criar automaticamente todas"):
 * - `restricoes.quadro|resolver` ← quem hoje tem `editar`
 *   (RestricaoPolicy::resolver() usava o fallback 'editar').
 * - `restricoes.quadro|reabrir` ← quem hoje tem `editar`
 *   (RestricaoPolicy::reabrir() usava o fallback 'editar').
 * - `engenharia.pacotes|liberar_para_construcao` ← quem hoje tem
 *   `editar` (exemplo literal do pedido, Seção 18 —
 *   AlterarLiberacaoRevisaoDocumento era autorizada só por
 *   'editar' no caller).
 *
 * Depois deste backfill: ZERO perda de acesso — todo Perfil que hoje
 * consegue comentar/resolver/reabrir/liberar continua conseguindo,
 * agora via a chave nova e dedicada. `editar` deixa de ser exigido pra
 * comentar (a nova chave é independente — um admin pode revogar só
 * `comentar` no futuro sem tocar `editar`, e vice-versa).
 *
 * Mesmo padrão de `DB::table()` cru já estabelecido em
 * `2026_09_09_000001_backfill_perfil_permissoes_cadastros_unidade_familia.php`.
 */
return new class extends Migration
{
    /** @var array<int, array{0: string, 1: string, 2: string}> [funcionalidade, acao_origem, acao_nova] */
    private const MAPEAMENTO = [
        ['restricoes.quadro', 'criar', 'comentar'],
        ['restricoes.lookahead', 'editar', 'comentar'],
        ['report.relatorios', 'ver', 'comentar'],
        ['suprimentos.mapa', 'editar', 'comentar'],
        ['restricoes.quadro', 'editar', 'resolver'],
        ['restricoes.quadro', 'editar', 'reabrir'],
        ['engenharia.pacotes', 'editar', 'liberar_para_construcao'],
    ];

    public function up(): void
    {
        foreach (self::MAPEAMENTO as [$slug, $acaoOrigem, $acaoNova]) {
            DB::table('perfil_permissoes')
                ->where('funcionalidade', $slug)
                ->where('acao', $acaoOrigem)
                ->orderBy('id')
                ->chunkById(500, function ($linhas) use ($slug, $acaoNova) {
                    $novasLinhas = [];
                    $agora = now();

                    foreach ($linhas as $linha) {
                        $existe = DB::table('perfil_permissoes')
                            ->where('perfil_id', $linha->perfil_id)
                            ->where('funcionalidade', $slug)
                            ->where('acao', $acaoNova)
                            ->exists();

                        if (! $existe) {
                            $novasLinhas[] = [
                                'id' => (string) Str::ulid(),
                                'tenant_id' => $linha->tenant_id,
                                'perfil_id' => $linha->perfil_id,
                                'funcionalidade' => $slug,
                                'acao' => $acaoNova,
                                'created_at' => $agora,
                                'updated_at' => $agora,
                            ];
                        }
                    }

                    if ($novasLinhas !== []) {
                        DB::table('perfil_permissoes')->insert($novasLinhas);
                    }
                });
        }
    }

    public function down(): void
    {
        foreach (self::MAPEAMENTO as [$slug, , $acaoNova]) {
            DB::table('perfil_permissoes')
                ->where('funcionalidade', $slug)
                ->where('acao', $acaoNova)
                ->delete();
        }
    }
};
