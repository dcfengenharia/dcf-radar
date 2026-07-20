<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestricaoAcao extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    protected $table = 'restricao_acoes';

    protected $fillable = [
        'tenant_id',
        'restricao_id',
        'autor_id',
        'descricao',
    ];

    public function restricao(): BelongsTo
    {
        return $this->belongsTo(Restricao::class);
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'autor_id');
    }
}
