<?php

namespace App\Models;

use App\Enums\TipoItemTakeOff;
use App\Exceptions\ListaEngenhariaImutavelException;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ciclo 19, Etapa 19.1.CORREÇÃO/HARDENING — Lista de Materiais/
 * Instrumentos (LM/LI) como entidade própria. Pertence à Revisão (D1,
 * 19.0) — nunca ao Documento — e nunca é copiada/herdada por uma
 * revisão nova (mesmo princípio já aplicado 3x no projeto: PDF/status/
 * liberação nunca copiados de revisão pra revisão).
 *
 * `tipo` (Material|Instrumento) vive AQUI, não no item — uma lista é
 * inteiramente de um tipo só, então todo item dela compartilha o mesmo
 * tipo por construção, sem risco de drift entre as duas tabelas.
 *
 * **19.1.HARDENING — congelamento histórico**: enquanto a revisão dona
 * desta lista for a VIGENTE do Documento (mesma fonte canônica de
 * `DocumentoEngenharia::revisaoVigente()`, nunca uma segunda regra), a
 * lista e seus itens são livremente editáveis. Quando uma revisão mais
 * nova nasce, esta lista (e todos os seus itens) ficam congelados pra
 * sempre — `garantirEditavel()` é o único ponto de verdade dessa
 * checagem, reaproveitado pelo Observer da própria Lista, pelo Observer
 * de `ItemTakeOff`, e pelo componente Livewire (checagem antecipada,
 * defesa em profundidade — nunca confiar só em botão escondido na UI).
 */
class ListaEngenharia extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    protected $table = 'listas_engenharia';

    protected $fillable = [
        'tenant_id',
        'documento_engenharia_revisao_id',
        'tipo',
        'codigo',
        'titulo',
        'disciplina_id',
        'observacao',
    ];

    protected $casts = [
        'tipo' => TipoItemTakeOff::class,
    ];

    public function revisao(): BelongsTo
    {
        return $this->belongsTo(DocumentoEngenhariaRevisao::class, 'documento_engenharia_revisao_id');
    }

    public function disciplina(): BelongsTo
    {
        return $this->belongsTo(Disciplina::class);
    }

    public function itens(): HasMany
    {
        return $this->hasMany(ItemTakeOff::class, 'lista_engenharia_id');
    }

    /**
     * Fonte única de "esta lista pode ser editada agora?" — sempre
     * resolve a revisão vigente FRESH a partir do Documento (nunca
     * confia em relação já carregada potencialmente desatualizada),
     * reaproveitando `DocumentoEngenharia::revisaoVigente()` (mesma
     * ordem canônica de `scopeVigentes()`, nunca uma segunda regra).
     */
    public function estaVigente(): bool
    {
        $revisao = DocumentoEngenhariaRevisao::with('documento')->find($this->documento_engenharia_revisao_id);

        return $revisao?->documento?->revisaoVigente()?->id === $this->documento_engenharia_revisao_id;
    }

    public function garantirEditavel(): void
    {
        if (! $this->estaVigente()) {
            throw new ListaEngenhariaImutavelException(
                'Esta lista pertence a uma revisão que não é mais a vigente do documento — não pode ser editada, reimportada ou excluída. Crie uma nova lista na revisão vigente.'
            );
        }
    }
}
