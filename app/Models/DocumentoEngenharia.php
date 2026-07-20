<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentoEngenharia extends Model
{
    use BelongsToTenant, HasFactory, HasUlids, SoftDeletes;

    protected $table = 'documentos_engenharia';

    /**
     * status_documento_id NÃO é mais escrito/lido como fonte de verdade —
     * status agora é da emissão (DocumentoEngenhariaRevisao), nunca do
     * documento. Ver statusAtual(). A coluna fica órfã no banco (não foi
     * dropada, só parou de ser usada) até uma limpeza futura.
     */
    protected $fillable = [
        'tenant_id',
        'obra_id',
        'pacote_engenharia_id',
        'codigo',
        'descricao',
        'disciplina_id',
        'data_planejada',
        'data_realizada',
        'ordem',
    ];

    protected $casts = [
        'data_planejada' => 'date',
        'data_realizada' => 'date',
        'ordem' => 'integer',
    ];

    public function obra(): BelongsTo
    {
        return $this->belongsTo(Work::class, 'obra_id');
    }

    public function pacote(): BelongsTo
    {
        return $this->belongsTo(PacoteEngenharia::class, 'pacote_engenharia_id');
    }

    public function disciplina(): BelongsTo
    {
        return $this->belongsTo(Disciplina::class);
    }

    public function revisoes(): HasMany
    {
        return $this->hasMany(DocumentoEngenhariaRevisao::class, 'documento_engenharia_id')
            ->orderByDesc('data_emissao')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function revisaoAtual(): ?DocumentoEngenhariaRevisao
    {
        return $this->revisoes->first();
    }

    /**
     * "Revisão mais recente" pra fins de status atual — como relation
     * HasOne (ofMany) em vez de pegar o first() de revisoes(), permite
     * eager-load sem N+1 (with('latestRevisao.statusDocumento')) e
     * whereHas('latestRevisao', ...) pra filtrar por status atual de
     * verdade (não por qualquer revisão antiga que já teve aquele status).
     *
     * Ordena só por created_at/id (criação), NÃO por data_emissao como
     * revisoes()/revisaoAtual() — o MAX() do ofMany quebra quando todo um
     * grupo tem data_emissao NULL (comum: import não traz data), já que
     * NULL = NULL nunca casa em SQL. Na prática as duas ordens concordam
     * (revisões são criadas na ordem em que acontecem).
     */
    public function latestRevisao(): HasOne
    {
        return $this->hasOne(DocumentoEngenhariaRevisao::class, 'documento_engenharia_id')
            ->ofMany(['created_at' => 'max', 'id' => 'max']);
    }

    /**
     * A 1ª emissão registrada (mais antiga por ordem de criação, não por
     * data_emissao) — representa a "emissão real" do documento, imutável:
     * novas revisões depois dela nunca mudam qual foi a primeira. HasOne
     * (ofMany) pelo mesmo motivo de latestRevisao(): eager-load em massa
     * pro dashboard sem N+1.
     */
    public function primeiraRevisao(): HasOne
    {
        return $this->hasOne(DocumentoEngenhariaRevisao::class, 'documento_engenharia_id')
            ->ofMany(['created_at' => 'min', 'id' => 'min']);
    }

    public function reprogramacoes(): HasMany
    {
        return $this->hasMany(DocumentoEngenhariaReprogramacao::class, 'documento_engenharia_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function itensSuprimento(): BelongsToMany
    {
        return $this->belongsToMany(ItemSuprimento::class, 'item_suprimento_documentos')
            ->using(ItemSuprimentoDocumento::class);
    }

    /**
     * Status "de verdade" do documento — o da emissão mais recente. Enquanto
     * não houver nenhuma emissão, não existe status (null = "Não Emitido" na
     * UI, não é uma linha do catálogo StatusDocumento).
     */
    public function statusAtual(): ?StatusDocumento
    {
        return $this->latestRevisao?->statusDocumento;
    }

    public function estaEmitido(): bool
    {
        return $this->latestRevisao !== null;
    }

    public function primeiraEmissao(): ?DocumentoEngenhariaRevisao
    {
        return $this->primeiraRevisao;
    }

    public function dataEmissaoReal(): ?Carbon
    {
        return $this->primeiraRevisao?->data_emissao;
    }

    public function estaAtrasado(): bool
    {
        return !$this->estaEmitido() && (bool) $this->data_planejada?->isPast();
    }
}
