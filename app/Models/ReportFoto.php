<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ReportFoto extends Model
{
    use BelongsToTenant, HasUlids;

    protected $fillable = [
        'tenant_id',
        'report_id',
        'caminho_arquivo',
        'legenda',
        'ordem',
        'enviado_por',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function enviadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enviado_por');
    }

    public function getUrlAttribute(): ?string
    {
        return $this->caminho_arquivo && Storage::disk('public')->exists($this->caminho_arquivo)
            ? Storage::disk('public')->url($this->caminho_arquivo)
            : null;
    }

    /** Caminho absoluto no disco — usado pelo PDF (dompdf não lê URLs remotas). */
    public function getCaminhoAbsolutoAttribute(): ?string
    {
        return $this->caminho_arquivo && Storage::disk('public')->exists($this->caminho_arquivo)
            ? Storage::disk('public')->path($this->caminho_arquivo)
            : null;
    }
}
