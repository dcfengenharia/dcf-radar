<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Work extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'client_id',
        'name',
        'location',
        'budget_total',
        'start_date_baseline',
        'end_date_baseline',
        'status',
    ];

    protected $casts = [
        'start_date_baseline' => 'date',
        'end_date_baseline' => 'date',
        'budget_total' => 'float',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'obra_user', 'work_id', 'user_id')
            ->withPivot('papel', 'perfil_id')
            ->withTimestamps();
    }

    public function pacotesTrabalho(): HasMany
    {
        return $this->hasMany(PacoteTrabalho::class, 'obra_id');
    }

    public function atividades(): HasMany
    {
        return $this->hasMany(Atividade::class, 'obra_id');
    }

    public function getStatusBadgeAttribute(): string
    {
        return match ($this->status) {
            'planejamento' => 'bg-label-info',
            'em_andamento' => 'bg-label-success',
            'paralisada' => 'bg-label-danger',
            'concluida' => 'bg-label-secondary',
            default => 'bg-label-primary',
        };
    }

    protected static function booted(): void
    {
        static::created(function (Work $work) {
            $work->garantirCriadorDoTenantComoAdmin();
        });
    }

    /**
     * Quem criou o tenant é Admin em TODAS as obras, sempre — automático
     * e não removível/alterável pela aba Equipe (ver guards em
     * alterarPerfil()/removerMembro() em ⚡obra-detalhe.blade.php). Roda
     * na criação de toda obra nova; o backfill das obras que já
     * existiam roda uma vez na migration
     * 2026_07_11_000001_backfill_criador_admin_em_todas_obras.
     */
    public function garantirCriadorDoTenantComoAdmin(): void
    {
        $tenant = $this->tenant;
        if (! $tenant || ! $tenant->criado_por_id) {
            return;
        }

        $perfilAdmin = Perfil::porSlugPadrao($tenant, 'admin');
        if (! $perfilAdmin) {
            return;
        }

        $this->users()->syncWithoutDetaching([$tenant->criado_por_id => ['perfil_id' => $perfilAdmin->id]]);
    }
}
