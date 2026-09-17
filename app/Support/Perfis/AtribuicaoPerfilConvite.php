<?php

namespace App\Support\Perfis;

use App\Models\Convite;
use App\Models\ConvitePerfil;
use App\Models\Perfil;
use Illuminate\Support\Collection;

/**
 * FASE 2C, Seção 4-8 — Convite multiperfil. Um Convite passa a poder
 * carregar 1..N Perfis (via `convite_perfis`), preservando
 * compatibilidade total com convites legados que só têm
 * `convites.perfil_id` (Seção 5).
 *
 * Escrita SEMPRE via aqui (nunca `ConvitePerfil::create()` solto em
 * outro lugar) — mesma convenção de `App\Support\AtribuicaoPerfilObra`
 * pra `obra_user_perfil`.
 *
 * NUNCA usa o `TenantContext` ambiente pra decidir tenant — todo
 * filtro é explícito com `$convite->tenant_id`, porque
 * `perfisValidosParaAceite()` roda durante o fluxo de aceite de
 * convite, ANTES de `Auth::login()`, quando não existe usuário
 * autenticado (logo `TenantContext::currentId()` é `null` e o global
 * scope de tenant de `Perfil` fica inerte — confiar nele aqui
 * significaria consultar `perfis` de QUALQUER tenant).
 */
class AtribuicaoPerfilConvite
{
    /**
     * Grava o conjunto de perfis do convite — chamado só na CRIAÇÃO
     * (um Convite nunca é editado depois de criado: reenviar só
     * atualiza a expiração, cancelar só muda status — nunca precisa de
     * um "substituir"). Revalida o tenant de cada ID (defesa em
     * profundidade — Seção 7: nunca aceitar Perfil de outro tenant,
     * mesmo com um ID manualmente fornecido no payload).
     *
     * @param  array<int, string>  $perfilIds
     *
     * @throws \InvalidArgumentException  se algum ID não existir ou pertencer a outro tenant.
     */
    public static function gravar(Convite $convite, array $perfilIds): void
    {
        $perfilIds = array_values(array_unique($perfilIds));

        foreach ($perfilIds as $perfilId) {
            $perfil = Perfil::where('id', $perfilId)->where('tenant_id', $convite->tenant_id)->first();

            if ($perfil === null) {
                throw new \InvalidArgumentException('Perfil não pertence ao tenant deste convite.');
            }
        }

        foreach ($perfilIds as $perfilId) {
            ConvitePerfil::create([
                'tenant_id' => $convite->tenant_id,
                'convite_id' => $convite->id,
                'perfil_id' => $perfilId,
            ]);
        }
    }

    /**
     * Perfis VÁLIDOS pra conceder no momento do ACEITE — sempre
     * revalidados aqui, nunca confiados ao que foi gravado no envio,
     * porque um Perfil pode ter sido excluído (soft delete) ou deixado
     * de existir entre o envio e o aceite (Seção 7: "falha segura, não
     * atribuir parcialmente"). Regra explícita, documentada e testada:
     * concede exatamente o subconjunto que AINDA existe e pertence ao
     * MESMO tenant do convite no instante do aceite — um Perfil
     * inválido é simplesmente descartado do conjunto (nunca bloqueia o
     * aceite inteiro, nunca é concedido do jeito que estava antes de
     * ficar inválido). Se restar vazio, a membresia ainda é criada, só
     * sem nenhum perfil — mesmo estado já suportado por
     * `AtribuicaoPerfilObra::substituirPerfis([])`.
     *
     * `convite_perfis` (se tiver QUALQUER linha) é sempre a fonte de
     * verdade; cai pro `perfil_id` legado só quando não há NENHUMA
     * linha em `convite_perfis` — convite criado antes desta migração
     * (Seção 5: convites legados continuam válidos, sem backfill).
     *
     * @return array<int, string>
     */
    public static function perfisValidosParaAceite(Convite $convite): array
    {
        $idsPretendidos = ConvitePerfil::where('convite_id', $convite->id)->pluck('perfil_id')->all();

        if ($idsPretendidos === [] && $convite->perfil_id !== null) {
            $idsPretendidos = [$convite->perfil_id];
        }

        if ($idsPretendidos === []) {
            return [];
        }

        return Perfil::whereIn('id', $idsPretendidos)
            ->where('tenant_id', $convite->tenant_id)
            ->pluck('id')
            ->all();
    }

    /**
     * Os Perfis (models, já ordenados por nome) que este convite
     * concede — mesma fonte de `perfisValidosParaAceite()`, só que
     * devolvendo os models pra exibição (nome/descrição) em vez dos
     * IDs crus. Usado na listagem de "Convites pendentes" e no e-mail/
     * tela de aceite — nunca mostra o slug/ID técnico ao usuário.
     */
    public static function perfisParaExibicao(Convite $convite): Collection
    {
        $ids = self::perfisValidosParaAceite($convite);

        return $ids === [] ? collect() : Perfil::whereIn('id', $ids)->orderBy('nome')->get();
    }
}
