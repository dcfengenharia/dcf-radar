<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportPontoAtencao extends Model
{
    use BelongsToTenant, HasUlids;

    protected $table = 'report_pontos_atencao';

    protected $fillable = [
        'tenant_id',
        'report_curva_id',
        'categoria',
        'texto',
        'ordem',
    ];

    public function reportCurva(): BelongsTo
    {
        return $this->belongsTo(ReportCurva::class);
    }
}
