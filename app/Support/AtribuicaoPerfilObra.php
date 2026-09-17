<?php

namespace App\Support;

use App\Enums\OrigemEventoHistoricoAcesso;
use App\Models\ObraUserPerfil;
use App\Models\Perfil;
use App\Models\User;
use App\Models\Work;
use App\Support\Perfis\RegistrarEventoAcesso;
use Illuminate\Support\Facades\DB;

/**
 * FASE 2B, Seção 28 — ponte entre a UI legada de "1 perfil por membro"
 * (⚡obra-detalhe.blade.php, ⚡obras/create.blade.php,
 * Work::garantirCriadorDoTenantComoAdmin(), ConviteController,
 * TestCase::vincularObra()) e a nova estrutura multiperfil
 * (`obra_user_perfil`). Toda atribuição feita por essas telas continua
 * representando exatamente 1 perfil — `definirPerfilUnico()` GARANTE
 * que, depois dela, `obra_user_perfil` reflita EXATAMENTE esse perfil
 * pro par (obra, usuário), substituindo qualquer associação anterior
 * (nunca soma) — nenhuma dessas telas precisa saber que a nova pivot
 * existe.
 *
 * Nunca mexe em `obra_user` (membresia) nem no espelho legado
 * `obra_user.perfil_id` — cada caller já grava isso do jeito de sempre
 * (`attach()`/`syncWithoutDetaching()`/`updateExistingPivot()`/
 * `detach()`), sempre ANTES de chamar esta classe. Esta classe cuida
 * SÓ da nova pivot.
 *
 * FASE 2B.CORREÇÃO, Seção 9 — `adicionarPerfil()`/`removerPerfil()`/
 * `substituirPerfis()` são a base pra uma futura UX administrativa
 * multiperfil (Fase 2C): manipulam a coleção efetiva de perfis do par
 * (obra, usuário) diretamente, sem a restrição de "exatamente 1" que
 * `definirPerfilUnico()` impõe pra UI legada. Como o resolver central
 * (`App\Models\Concerns\HasObraPapel::perfisIdsNaObra()`) trata a nova
 * pivot como AUTORIDADE COMPLETA assim que ela tem qualquer linha pro
 * par, essas duas garantias são mantidas em toda escrita:
 * - **nunca ressuscitar legado (Seção 11)**: se uma remoção esvazia por
 *   completo a coleção do par, o espelho legado também é zerado — senão
 *   o fallback de compatibilidade (só usado quando a pivot está vazia)
 *   voltaria a conceder um perfil que a fonte de verdade já revogou;
 * - **nunca aceitar Perfil de outro tenant (Seção 13)**, mesmo com um ID
 *   manualmente fornecido — validado antes de qualquer escrita.
 *
 * FASE 2D, Seção 30 — os 4 métodos mutadores ganharam `?User $ator` +
 * `?OrigemEventoHistoricoAcesso $origem` OPCIONAIS (default `null`):
 * quando os DOIS são informados, a mudança é registrada em
 * `historico_acessos` (via `RegistrarEventoAcesso`), dentro da MESMA
 * transação da escrita (Seção 31). Quando omitidos (o padrão), a
 * chamada continua se comportando exatamente como antes desta fase —
 * SEM nenhum evento. Essa é uma decisão EXPLÍCITA de arquitetura (Seção
 * 30, opção escolhida: centralizar dentro do helper compartilhado, não
 * em cada um dos ~7 call sites de produção): cada caller precisa decidir
 * conscientemente se aquela atribuição é uma decisão de acesso
 * auditável — os 2 bootstraps estruturais do sistema
 * (`Work::garantirCriadorDoTenantComoAdmin()`/auto-vínculo do criador da
 * obra em `⚡obras/create.blade.php`, ambos deterministas e nunca uma
 * escolha administrativa discricionária) e o fluxo de aceite de convite
 * (que registra seu PRÓPRIO evento mais rico, `conviteAceito()`, pra
 * nunca duplicar — Seção 7) deliberadamente NÃO passam esses parâmetros.
 * Ver relatório final da Fase 2D pra a lista completa e justificada de
 * quem passa e quem não passa.
 */
class AtribuicaoPerfilObra
{
    /**
     * @param  string|null  $perfilId  null = remover todas as
     *     atribuições do par (obra, usuário) sem definir nenhuma nova —
     *     usado por `removerMembro()`.
     */
    public static function definirPerfilUnico(
        Work $obra,
        string $userId,
        ?string $perfilId,
        ?User $ator = null,
        ?OrigemEventoHistoricoAcesso $origem = null
    ): void {
        if ($perfilId !== null) {
            self::garantirPerfilDoTenant($obra, $perfilId);
        }

        DB::transaction(function () use ($obra, $userId, $perfilId, $ator, $origem) {
            $perfisAntes = $ator ? self::perfisAtuais($obra, $userId) : null;

            ObraUserPerfil::where('work_id', $obra->id)->where('user_id', $userId)->delete();

            if ($perfilId !== null) {
                ObraUserPerfil::create([
                    'tenant_id' => $obra->tenant_id,
                    'work_id' => $obra->id,
                    'user_id' => $userId,
                    'perfil_id' => $perfilId,
                ]);
            }

            if ($ator !== null && $origem !== null) {
                $usuarioAfetado = User::find($userId);
                $perfisDepois = $perfilId !== null ? Perfil::whereIn('id', [$perfilId])->get() : collect();

                if ($usuarioAfetado !== null) {
                    RegistrarEventoAcesso::perfisAtribuidos($ator, $obra, $usuarioAfetado, $perfisAntes, $perfisDepois, $origem);
                }
            }
        });
    }

    /**
     * Perfis EFETIVOS atuais do par (obra, usuário), como models
     * (nunca só IDs) — usado só pelo "antes" dos eventos de auditoria,
     * sempre lido ANTES de qualquer escrita desta classe na mesma
     * chamada.
     *
     * @return \Illuminate\Support\Collection<int, Perfil>
     */
    private static function perfisAtuais(Work $obra, string $userId): \Illuminate\Support\Collection
    {
        $ids = ObraUserPerfil::where('work_id', $obra->id)->where('user_id', $userId)->pluck('perfil_id');

        if ($ids->isNotEmpty()) {
            return Perfil::whereIn('id', $ids)->get();
        }

        // Fallback de compatibilidade (mesma regra de HasObraPapel::
        // perfisIdsNaObra() — pivot vazia cai pro espelho legado).
        $legado = DB::table('obra_user')->where('work_id', $obra->id)->where('user_id', $userId)->value('perfil_id');

        return $legado !== null ? Perfil::whereIn('id', [$legado])->get() : collect();
    }

    /**
     * Remove TODAS as atribuições do par (obra, usuário) — usado quando
     * o membro é removido da equipe (a membresia em `obra_user` já foi
     * apagada pelo caller; esta chamada só limpa a pivot de perfis).
     */
    public static function removerTodas(Work $obra, string $userId): void
    {
        ObraUserPerfil::where('work_id', $obra->id)->where('user_id', $userId)->delete();
    }

    /**
     * Adiciona um perfil à coleção existente do par (obra, usuário), SEM
     * remover nenhum já atribuído — diferente de `definirPerfilUnico()`,
     * que sempre substitui. Nunca mexe no espelho legado (que continua
     * representando o "perfil primário" pra exibição/compatibilidade,
     * ver `HasObraPapel::perfilNaObra()`) — adicionar um perfil
     * secundário não deveria reinterpretar qual é o primário.
     * `firstOrCreate()` evita duplicar se chamado 2x pro mesmo perfil.
     */
    public static function adicionarPerfil(
        Work $obra,
        string $userId,
        string $perfilId,
        ?User $ator = null,
        ?OrigemEventoHistoricoAcesso $origem = null
    ): void {
        self::garantirPerfilDoTenant($obra, $perfilId);

        DB::transaction(function () use ($obra, $userId, $perfilId, $ator, $origem) {
            $perfisAntes = $ator ? self::perfisAtuais($obra, $userId) : null;

            ObraUserPerfil::firstOrCreate([
                'work_id' => $obra->id,
                'user_id' => $userId,
                'perfil_id' => $perfilId,
            ], [
                'tenant_id' => $obra->tenant_id,
            ]);

            if ($ator !== null && $origem !== null) {
                $usuarioAfetado = User::find($userId);
                $perfisDepois = self::perfisAtuais($obra, $userId);

                if ($usuarioAfetado !== null) {
                    RegistrarEventoAcesso::perfisAtribuidos($ator, $obra, $usuarioAfetado, $perfisAntes, $perfisDepois, $origem);
                }
            }
        });
    }

    /**
     * Remove APENAS o perfil indicado da coleção do par (obra, usuário)
     * — os demais permanecem intactos (Seção 10 do pedido de correção:
     * este é o teste crítico que prova que remover um perfil nunca
     * afeta os outros).
     *
     * O espelho legado (`obra_user.perfil_id`) é sempre mantido coerente
     * com o que sobra, nos dois cenários possíveis:
     * - **Se esta for a ÚLTIMA associação restante do par**, o legado
     *   também é zerado — garantia de determinismo da Seção 11: "zero
     *   perfis efetivos" nunca pode, depois disso, ressuscitar via o
     *   fallback de compatibilidade do resolver (que só se aplica quando
     *   a pivot está vazia).
     * - **Se sobrarem outros perfis, mas o legado apontava exatamente
     *   pro perfil que acabou de ser removido**, o legado é reapontado
     *   pro primeiro perfil remanescente — nunca deixado "pendurado" num
     *   perfil que já não faz mais parte da coleção efetiva. Sem essa
     *   correção, uma checagem que ainda lê o legado cru (ex.: o guard
     *   de "existe outro Admin no tenant?" em
     *   ⚡obra-detalhe.blade.php::removeriaOUltimoAdmin()) poderia
     *   enxergar um "Admin fantasma" que a nova pivot já revogou.
     */
    public static function removerPerfil(
        Work $obra,
        string $userId,
        string $perfilId,
        ?User $ator = null,
        ?OrigemEventoHistoricoAcesso $origem = null
    ): void {
        DB::transaction(function () use ($obra, $userId, $perfilId, $ator, $origem) {
            $perfisAntes = $ator ? self::perfisAtuais($obra, $userId) : null;

            $removidos = ObraUserPerfil::where('work_id', $obra->id)
                ->where('user_id', $userId)
                ->where('perfil_id', $perfilId)
                ->delete();

            if ($removidos === 0) {
                return;
            }

            $restantes = ObraUserPerfil::where('work_id', $obra->id)
                ->where('user_id', $userId)
                ->pluck('perfil_id');

            if ($restantes->isEmpty()) {
                $obra->users()->updateExistingPivot($userId, ['perfil_id' => null]);
            } else {
                $legadoAtual = DB::table('obra_user')
                    ->where('work_id', $obra->id)
                    ->where('user_id', $userId)
                    ->value('perfil_id');

                if ($legadoAtual === $perfilId) {
                    $obra->users()->updateExistingPivot($userId, ['perfil_id' => $restantes->first()]);
                }
            }

            if ($ator !== null && $origem !== null) {
                $usuarioAfetado = User::find($userId);

                if ($usuarioAfetado !== null) {
                    $perfisDepois = $restantes->isEmpty() ? collect() : Perfil::whereIn('id', $restantes)->get();
                    RegistrarEventoAcesso::perfisAtribuidos($ator, $obra, $usuarioAfetado, $perfisAntes, $perfisDepois, $origem);
                }
            }
        });
    }

    /**
     * Substitui a coleção INTEIRA de perfis do par (obra, usuário) pelo
     * conjunto informado (0..N) — generalização de `definirPerfilUnico()`
     * pra múltiplos perfis simultâneos, base pra a futura UX multiperfil
     * (Fase 2C). O espelho legado é sempre sincronizado deterministicamente:
     * primeiro elemento do conjunto novo (ou `null` se o conjunto for
     * vazio) — nunca deixado divergente do que a nova pivot passa a
     * representar.
     *
     * @param  array<int, string>  $perfilIds
     */
    public static function substituirPerfis(
        Work $obra,
        string $userId,
        array $perfilIds,
        ?User $ator = null,
        ?OrigemEventoHistoricoAcesso $origem = null
    ): void {
        $perfilIds = array_values(array_unique($perfilIds));

        foreach ($perfilIds as $perfilId) {
            self::garantirPerfilDoTenant($obra, $perfilId);
        }

        DB::transaction(function () use ($obra, $userId, $perfilIds, $ator, $origem) {
            $perfisAntes = $ator ? self::perfisAtuais($obra, $userId) : null;

            ObraUserPerfil::where('work_id', $obra->id)->where('user_id', $userId)->delete();

            foreach ($perfilIds as $perfilId) {
                ObraUserPerfil::create([
                    'tenant_id' => $obra->tenant_id,
                    'work_id' => $obra->id,
                    'user_id' => $userId,
                    'perfil_id' => $perfilId,
                ]);
            }

            $obra->users()->updateExistingPivot($userId, [
                'perfil_id' => $perfilIds[0] ?? null,
            ]);

            if ($ator !== null && $origem !== null) {
                $usuarioAfetado = User::find($userId);

                if ($usuarioAfetado !== null) {
                    $perfisDepois = $perfilIds === [] ? collect() : Perfil::whereIn('id', $perfilIds)->get();
                    RegistrarEventoAcesso::perfisAtribuidos($ator, $obra, $usuarioAfetado, $perfisAntes, $perfisDepois, $origem);
                }
            }
        });
    }

    /**
     * Defesa em profundidade (Seção 13 da correção) — nenhuma das
     * operações que CONCEDEM perfil (adicionar/substituir/definir) pode
     * aceitar um Perfil de outro tenant, mesmo com um ID manualmente
     * fornecido (payload manipulado, bug de caller). Nunca confia em
     * filtro de UI — revalida aqui, no único lugar que efetivamente
     * escreve a nova pivot.
     */
    private static function garantirPerfilDoTenant(Work $obra, string $perfilId): void
    {
        $perfil = Perfil::find($perfilId);

        if (! $perfil || $perfil->tenant_id !== $obra->tenant_id) {
            throw new \InvalidArgumentException('Perfil não pertence ao tenant desta obra.');
        }
    }
}
