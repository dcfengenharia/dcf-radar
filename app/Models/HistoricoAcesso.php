<?php

namespace App\Models;

use App\Enums\OrigemEventoHistoricoAcesso;
use App\Enums\TipoEventoHistoricoAcesso;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FASE 2D — evento de governança/auditoria de acesso, append-only
 * (`App\Observers\HistoricoAcessoObserver` bloqueia `updating()`/
 * `deleting()` incondicionalmente). Escrito SEMPRE via
 * `App\Support\Perfis\RegistrarEventoAcesso` — nunca `create()` solto
 * em componentes/controllers (Seção 29).
 *
 * Toda relação (`ator`/`usuarioAfetado`/`perfil`/`obra`) é só uma
 * CONVENIÊNCIA de leitura pro estado ATUAL, quando ainda existir —
 * NUNCA a fonte de verdade pra exibição histórica: `*_nome_snapshot` +
 * `resumo` + `detalhes` já carregam tudo que a UI precisa, mesmo depois
 * do Perfil/usuário/obra referenciado ter sido excluído (Seção 9/23 —
 * FKs são `nullOnDelete()`, nunca `cascadeOnDelete()`).
 */
class HistoricoAcesso extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'historico_acessos';

    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'obra_id',
        'tipo_evento',
        'origem',
        'ator_user_id',
        'ator_nome_snapshot',
        'ator_platform_admin',
        'ator_impersonando',
        'usuario_afetado_id',
        'usuario_afetado_nome_snapshot',
        'perfil_id',
        'perfil_nome_snapshot',
        'resumo',
        'detalhes',
    ];

    protected $casts = [
        'tipo_evento' => TipoEventoHistoricoAcesso::class,
        'origem' => OrigemEventoHistoricoAcesso::class,
        'ator_platform_admin' => 'boolean',
        'ator_impersonando' => 'boolean',
        'detalhes' => 'array',
        'created_at' => 'datetime',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function ator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ator_user_id');
    }

    public function usuarioAfetado(): BelongsTo
    {
        return $this->belongsTo(User::class, 'usuario_afetado_id');
    }

    public function perfil(): BelongsTo
    {
        return $this->belongsTo(Perfil::class);
    }

    /**
     * Nome do ator pra exibição — sempre o snapshot (congelado no
     * instante do evento), nunca o cadastro vivo (que pode ter mudado
     * de nome, ou o usuário pode ter sido removido).
     */
    public function nomeAtorExibicao(): string
    {
        return $this->ator_nome_snapshot ?? 'Usuário removido';
    }

    public function nomeUsuarioAfetadoExibicao(): ?string
    {
        if ($this->usuario_afetado_id === null && $this->usuario_afetado_nome_snapshot === null) {
            return null;
        }

        return $this->usuario_afetado_nome_snapshot ?? 'Usuário removido';
    }

    public function nomePerfilExibicao(): ?string
    {
        if ($this->perfil_id === null && $this->perfil_nome_snapshot === null) {
            return null;
        }

        return $this->perfil_nome_snapshot ?? 'Perfil excluído';
    }
}
