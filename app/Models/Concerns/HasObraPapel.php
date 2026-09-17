<?php

namespace App\Models\Concerns;

use App\Models\ObraUserPerfil;
use App\Models\Perfil;
use App\Models\PerfilPermissao;
use App\Models\Work;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * FASE 2B — CORE RBAC CONTEXTUAL PROFISSIONAL (Seção 33 do pedido:
 * "fachada única" — callers não precisam saber quantos perfis existem,
 * qual pivot existe, ou como a união funciona; só perguntam "usuário
 * pode X na obra Y?"). A API pública (`temAcessoAObra`/
 * `temPermissaoNaObra`/`temPermissaoEmAlgumaObraDoTenant`) é EXATAMENTE
 * a mesma de antes desta fase — nenhum caller precisou mudar por causa
 * do multiperfil, incluindo Home Executiva, Cockpits, Menu, todas as
 * Policies e todos os `garantirPermissao()`/`abort_unless()` espalhados
 * pelo projeto.
 *
 * **FASE 2B.CORREÇÃO (Seção 5/6 do pedido de fechamento adversarial)**:
 * a versão original desta fase fazia uma UNIÃO PERMANENTE entre a nova
 * pivot (`App\Models\ObraUserPerfil`) e o espelho legado
 * (`obra_user.perfil_id`) — isso permitia "revogação fantasma": remover
 * um perfil só da nova pivot nunca revogava a capacidade de verdade,
 * porque o legado continuava sendo somado à união indefinidamente.
 * Corrigido: a nova pivot, **uma vez que tenha QUALQUER linha pra um
 * par (obra, usuário), é AUTORIDADE COMPLETA** — o legado deixa de ser
 * consultado, mesmo que divirja. O legado só é usado como FALLBACK DE
 * COMPATIBILIDADE quando a nova pivot está **totalmente vazia** pra
 * aquele par (registro ainda não migrado pra nenhuma associação nova).
 * `App\Support\AtribuicaoPerfilObra::removerPerfil()` garante que essa
 * regra nunca produz uma "ressurreição" indevida: ao remover a ÚLTIMA
 * associação restante de um par, ela também zera o espelho legado —
 * então "zero perfis efetivos" nunca cai de volta no fallback.
 *
 * **GRANT por qualquer perfil = capacidade concedida (Seção 7/8)**:
 * `temPermissaoNaObra()` faz a UNIÃO (nunca interseção) das permissões
 * de todos os perfis do usuário na obra — sem DENY explícito, sem
 * precedência, sem hierarquia implícita (Seção 46).
 *
 * **Cache/performance (Seção 34)**: cache em memória por instância,
 * por request — nenhuma chamada repetida (mesmo item de menu
 * renderizado N vezes, N cards de Cockpit, N linhas de uma listagem)
 * dispara query nova depois da primeira. Com múltiplos perfis, as
 * permissões de TODOS eles são carregadas numa ÚNICA query em lote
 * (`whereIn perfil_id`) na primeira checagem de qualquer permissão
 * naquela obra — nunca 1 query por perfil. `esquecerCachePerfisNaObra()`
 * (Seção 15 da correção) invalida esse cache quando o MESMO objeto
 * `User` em memória precisa refletir uma mutação de perfis feita
 * durante o mesmo lifecycle/request — a maioria dos callers nunca
 * precisa disso, já que buscam um `User` fresco (`User::find()`) depois
 * de qualquer mutação.
 */
trait HasObraPapel
{
    /** obraId => perfil_id legado (obra_user.perfil_id) ou null. */
    private array $perfilIdLegadoPorObraCache = [];

    /** obraId => bool — existe linha em obra_user pra este par (membresia)? */
    private array $membroPorObraCache = [];

    /** obraId => array<string> de perfil_ids efetivos (pivot nova quando populada, senão fallback legado). */
    private array $perfisIdsPorObraCache = [];

    /** perfilId => mapa ["funcionalidade|acao" => true]. */
    private array $permissoesPorPerfilCache = [];

    /** obraId => mapa união ["funcionalidade|acao" => true] de TODOS os perfis do usuário naquela obra. */
    private array $permissoesUniaoPorObraCache = [];

    private array $permissaoTenantCache = [];

    /**
     * Resolve MEMBRESIA + espelho legado numa ÚNICA query (a mesma que
     * sempre existiu, agora também alimentando o cache de membresia) —
     * nunca uma query extra só pra confirmar "é membro?".
     */
    private function resolverMembroLegadoNaObra(string $obraId): void
    {
        if (array_key_exists($obraId, $this->membroPorObraCache)) {
            return;
        }

        $work = $this->works()->where('works.id', $obraId)->first();

        $this->membroPorObraCache[$obraId] = $work !== null;
        $this->perfilIdLegadoPorObraCache[$obraId] = $work?->pivot->perfil_id;
    }

    private function perfilIdLegadoNaObra(Work|string $obra): ?string
    {
        $obraId = $obra instanceof Work ? $obra->id : $obra;
        $this->resolverMembroLegadoNaObra($obraId);

        return $this->perfilIdLegadoPorObraCache[$obraId];
    }

    /**
     * `perfil_id`s efetivos do usuário nesta obra — a nova pivot
     * (`obra_user_perfil`), uma vez POPULADA pra este par, é AUTORIDADE
     * COMPLETA (o legado nunca é consultado, mesmo que divirja); só
     * quando a nova pivot está totalmente vazia pra este par o espelho
     * legado (`obra_user.perfil_id`) é usado como fallback de
     * compatibilidade (Seção 5/6 da correção — nunca uma união
     * permanente das duas fontes, que produzia "revogação fantasma").
     * 2 queries no pior caso (nunca N), cacheado por obra pro resto do
     * request.
     *
     * **MEMBRESIA (`obra_user`) É O PORTÃO (Seção 4 do pedido)** — sem
     * linha em `obra_user` pra este par, retorna `[]` IMEDIATAMENTE,
     * mesmo que `obra_user_perfil` ainda tenha alguma associação órfã
     * (ex.: membro removido da equipe por um caminho que apagou
     * `obra_user` sem passar por `App\Support\AtribuicaoPerfilObra::
     * removerTodas()` — nunca deveria acontecer pelos callers desta
     * fase, mas um dado inconsistente/manipulado nunca deveria CONCEDER
     * acesso por acidente). `obra_user_perfil` é sempre um ADICIONAL
     * sobre a membresia, nunca um substituto dela.
     *
     * @return array<int, string>
     */
    private function perfisIdsNaObra(Work|string $obra): array
    {
        $obraId = $obra instanceof Work ? $obra->id : $obra;

        if (array_key_exists($obraId, $this->perfisIdsPorObraCache)) {
            return $this->perfisIdsPorObraCache[$obraId];
        }

        $this->resolverMembroLegadoNaObra($obraId);

        if (! $this->membroPorObraCache[$obraId]) {
            return $this->perfisIdsPorObraCache[$obraId] = [];
        }

        $daNovaPivot = array_values(array_unique(
            ObraUserPerfil::query()
                ->where('work_id', $obraId)
                ->where('user_id', $this->id)
                ->pluck('perfil_id')
                ->all()
        ));

        if ($daNovaPivot !== []) {
            return $this->perfisIdsPorObraCache[$obraId] = $daNovaPivot;
        }

        $legado = $this->perfilIdLegadoPorObraCache[$obraId];

        return $this->perfisIdsPorObraCache[$obraId] = $legado !== null ? [$legado] : [];
    }

    /**
     * Invalida o cache em memória de perfis/permissões desta obra (ou de
     * TODAS as obras, se `$obra` for omitido) — necessário só quando o
     * MESMO objeto `User` em memória precisa refletir uma mutação de
     * perfis feita durante o mesmo lifecycle/request (Seção 15 da
     * correção). Callers que sempre buscam um `User` fresco
     * (`User::find()`/`->fresh()`) após a mutação nunca precisam disto —
     * uma instância nova simplesmente não tem cache nenhum ainda.
     */
    public function esquecerCachePerfisNaObra(Work|string|null $obra = null): void
    {
        if ($obra === null) {
            $this->perfilIdLegadoPorObraCache = [];
            $this->membroPorObraCache = [];
            $this->perfisIdsPorObraCache = [];
            $this->permissoesUniaoPorObraCache = [];
            $this->permissoesPorPerfilCache = [];
            $this->permissaoTenantCache = [];

            return;
        }

        $obraId = $obra instanceof Work ? $obra->id : $obra;

        unset(
            $this->perfilIdLegadoPorObraCache[$obraId],
            $this->membroPorObraCache[$obraId],
            $this->perfisIdsPorObraCache[$obraId],
            $this->permissoesUniaoPorObraCache[$obraId],
        );
    }

    /**
     * Todos os Perfis (0..N) que o usuário tem nesta obra — base pra uma
     * futura UX administrativa de múltiplos perfis (Fase 2C). Nesta
     * fase, nenhuma tela nova consome isto além de `perfilNaObra()`/
     * `temPerfilNaObra()` abaixo.
     */
    public function perfisNaObra(Work|string $obra): Collection
    {
        $ids = $this->perfisIdsNaObra($obra);

        return $ids === [] ? collect() : Perfil::whereIn('id', $ids)->get();
    }

    /**
     * Acessor de EXIBIÇÃO/compatibilidade — "o" perfil do usuário nesta
     * obra, pro caso comum (ainda hoje: 1 único perfil) e pra UI legada
     * que nunca foi redesenhada nesta fase (navbar, listagem de
     * Restrições mostrando o perfil do responsável). Prioriza o espelho
     * legado (o que a UI de "1 perfil" atribuiu por último); cai pro
     * primeiro da união só se não houver espelho legado (ex.: atribuição
     * futura feita 100% pela nova pivot, sem nunca passar pela UI
     * legada). Com múltiplos perfis reais, mostra só UM deles — aceito
     * nesta fase (Seção 26: "não redesenhar" a exibição), correto será
     * decidido na UX de Fase 2C.
     */
    public function perfilNaObra(Work|string $obra): ?Perfil
    {
        $legadoId = $this->perfilIdLegadoNaObra($obra);
        if ($legadoId !== null) {
            return Perfil::find($legadoId);
        }

        $ids = $this->perfisIdsNaObra($obra);

        return $ids === [] ? null : Perfil::find($ids[0]);
    }

    /**
     * "O usuário tem, entre os perfis que possui nesta obra, algum cujo
     * slug_padrao é $slugPadrao?" — substitui o padrão antigo e frágil
     * `perfilNaObra($obra)?->slug_padrao === X` (que só olhava "o"
     * perfil singular) por uma checagem que considera TODOS os perfis
     * do usuário, correta sob multiperfil (ex.: usuário com Admin +
     * Planejamento continua contando como Admin).
     */
    public function temPerfilNaObra(Work|string $obra, string $slugPadrao): bool
    {
        return $this->perfisNaObra($obra)->contains(fn (Perfil $p) => $p->slug_padrao === $slugPadrao);
    }

    /**
     * "Usuário tem ALGUM perfil nesta obra?" — mesma semântica de
     * SEMPRE antes desta fase (perfilIdNaObra() !== null), preservada
     * bit-a-bit: membresia (`obra_user`) SEM nenhum perfil associado
     * conta como SEM acesso (Seção 30 do pedido, decisão explícita:
     * "membresia sem perfil = nenhuma capability de Perfil", sem
     * fallback permissivo).
     */
    public function temAcessoAObra(Work|string $obra): bool
    {
        return $this->perfisIdsNaObra($obra) !== [];
    }

    /**
     * União das permissões de TODOS os perfis do usuário nesta obra —
     * GRANT por qualquer perfil já basta (Seção 7/8, sem DENY explícito).
     * Perfil vazio (Seção 31) contribui `[]` à união, nunca bloqueia os
     * demais.
     */
    public function temPermissaoNaObra(Work|string $obra, string $funcionalidade, string $acao): bool
    {
        $obraId = $obra instanceof Work ? $obra->id : $obra;

        return $this->permissoesUniaoNaObra($obraId)[$funcionalidade.'|'.$acao] ?? false;
    }

    private function permissoesUniaoNaObra(string $obraId): array
    {
        if (array_key_exists($obraId, $this->permissoesUniaoPorObraCache)) {
            return $this->permissoesUniaoPorObraCache[$obraId];
        }

        $perfisIds = $this->perfisIdsNaObra($obraId);

        if ($perfisIds === []) {
            return $this->permissoesUniaoPorObraCache[$obraId] = [];
        }

        // Carrega em UMA query só (whereIn) as permissões de todo perfil
        // ainda não cacheado — nunca 1 query por perfil, mesmo com o
        // usuário tendo N perfis nesta obra.
        $faltantes = array_values(array_diff($perfisIds, array_keys($this->permissoesPorPerfilCache)));

        if ($faltantes !== []) {
            $porPerfil = [];
            foreach (PerfilPermissao::whereIn('perfil_id', $faltantes)->get(['perfil_id', 'funcionalidade', 'acao']) as $linha) {
                $porPerfil[$linha->perfil_id][$linha->funcionalidade.'|'.$linha->acao] = true;
            }

            foreach ($faltantes as $perfilId) {
                $this->permissoesPorPerfilCache[$perfilId] = $porPerfil[$perfilId] ?? [];
            }
        }

        $uniao = [];
        foreach ($perfisIds as $perfilId) {
            $uniao += $this->permissoesPorPerfilCache[$perfilId];
        }

        return $this->permissoesUniaoPorObraCache[$obraId] = $uniao;
    }

    /**
     * Pra funcionalidades tenant-level (Cadastros) sem contexto de obra
     * específico — "tem esta permissão em QUALQUER obra do tenant,
     * considerando TODOS os perfis de TODAS as obras?". Generaliza o
     * padrão já usado por DisciplinaPolicy/CategoriaRestricaoPolicy.
     *
     * FASE 2B.CORREÇÃO (Seção 6) — a versão original consultava as duas
     * fontes (legado tenant-wide, nova pivot tenant-wide) como uma UNIÃO
     * independente uma da outra, reintroduzindo a mesma "revogação
     * fantasma" do resolver por obra num nível mais amplo (uma obra cuja
     * nova pivot já é autoridade — porque tem alguma associação — mas
     * cujo legado diverge continuaria concedendo via `viaLegado`).
     * Corrigido pra aplicar, OBRA POR OBRA, a MESMA regra de autoridade
     * do resolver principal (`perfisIdsNaObra()`: pivot populada pra uma
     * obra é autoridade completa PRA AQUELA OBRA, legado só entra como
     * fallback quando a pivot está vazia pra ela) — nunca uma união
     * cega das duas fontes. 3 queries no total, independente de quantas
     * obras o usuário tem (nunca N+1).
     *
     * Bootstrap: se o usuário ainda não tem NENHUMA linha em obra_user
     * (tenant recém-criado, criador ainda não fez a primeira obra),
     * libera por padrão — senão o próprio criador do tenant ficaria
     * travado sem conseguir abrir Cadastros > Clientes pra cadastrar o
     * primeiro cliente antes de conseguir criar a primeira obra.
     */
    public function temPermissaoEmAlgumaObraDoTenant(string $funcionalidade, string $acao): bool
    {
        $cacheKey = $funcionalidade.'|'.$acao;

        if (array_key_exists($cacheKey, $this->permissaoTenantCache)) {
            return $this->permissaoTenantCache[$cacheKey];
        }

        $membresias = DB::table('obra_user')->where('user_id', $this->id)->get(['work_id', 'perfil_id']);

        if ($membresias->isEmpty()) {
            return $this->permissaoTenantCache[$cacheKey] = true;
        }

        $daNovaPivotPorObra = DB::table('obra_user_perfil')
            ->where('user_id', $this->id)
            ->whereIn('work_id', $membresias->pluck('work_id'))
            ->get(['work_id', 'perfil_id'])
            ->groupBy('work_id');

        $perfisIdsEfetivos = [];
        foreach ($membresias as $membresia) {
            $daPivotDestaObra = $daNovaPivotPorObra->get($membresia->work_id);

            if ($daPivotDestaObra !== null && $daPivotDestaObra->isNotEmpty()) {
                foreach ($daPivotDestaObra as $linha) {
                    $perfisIdsEfetivos[$linha->perfil_id] = true;
                }
            } elseif ($membresia->perfil_id !== null) {
                $perfisIdsEfetivos[$membresia->perfil_id] = true;
            }
        }

        if ($perfisIdsEfetivos === []) {
            return $this->permissaoTenantCache[$cacheKey] = false;
        }

        return $this->permissaoTenantCache[$cacheKey] = PerfilPermissao::whereIn('perfil_id', array_keys($perfisIdsEfetivos))
            ->where('funcionalidade', $funcionalidade)
            ->where('acao', $acao)
            ->exists();
    }
}
