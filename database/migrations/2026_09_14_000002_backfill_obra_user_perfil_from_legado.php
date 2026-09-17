<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * FASE 2B, Seção 5 — backfill aditivo e seguro: para cada `obra_user`
 * que hoje possui `perfil_id`, cria exatamente 1 associação
 * correspondente em `obra_user_perfil`. Depois deste backfill, a
 * permissão efetiva de todo usuário/obra é semanticamente IDÊNTICA ao
 * estado anterior — nenhum acesso desaparece, nenhuma obra perde
 * membro, nenhum perfil é perdido.
 *
 * `DB::table()` cru em toda leitura/escrita (nunca os models `Work`/
 * `User`/`ObraUserPerfil`) — mesmo padrão já estabelecido no projeto
 * pra migrations de backfill cross-tenant (ver
 * `2026_09_09_000001_backfill_perfil_permissoes_cadastros_unidade_familia.php`):
 * `Model::create()` dispara `BelongsToTenant::creating()`, que
 * sobrescreveria silenciosamente `tenant_id` se houvesse uma sessão
 * autenticada no contexto de quem roda `artisan migrate` — inserção
 * crua nunca dispara esse hook, imune à classe inteira desse problema.
 *
 * Idempotente: `insertOrIgnore()` sobre o mesmo `unique(work_id,
 * user_id, perfil_id)` da migration anterior — rodar esta migration
 * duas vezes (ex.: `migrate:rollback` seguido de `migrate` de novo)
 * nunca duplica linha.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('obra_user')
            ->whereNotNull('perfil_id')
            ->orderBy('work_id')
            ->orderBy('user_id')
            ->chunk(500, function ($linhas) {
                $agora = now();

                $novasLinhas = [];
                foreach ($linhas as $linha) {
                    $tenantId = DB::table('works')->where('id', $linha->work_id)->value('tenant_id');
                    if ($tenantId === null) {
                        continue; // obra órfã (não deveria existir) — nunca inventa tenant
                    }

                    $novasLinhas[] = [
                        'id' => (string) Str::ulid(),
                        'tenant_id' => $tenantId,
                        'work_id' => $linha->work_id,
                        'user_id' => $linha->user_id,
                        'perfil_id' => $linha->perfil_id,
                        'created_at' => $agora,
                        'updated_at' => $agora,
                    ];
                }

                if ($novasLinhas !== []) {
                    DB::table('obra_user_perfil')->insertOrIgnore($novasLinhas);
                }
            });
    }

    public function down(): void
    {
        // Nunca apaga dado potencialmente criado por uma atribuição NOVA
        // (Fase 2B em diante) que coincida por acaso com o que este
        // backfill teria criado — down() desta migration é só best-effort
        // documental, nunca chamado em produção real (ver Seção 54 do
        // pedido: "nada de migration destrutiva nesta fase").
    }
};
