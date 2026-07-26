<?php

namespace App\Models;

use App\Enums\TipoFeedback;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Feedback extends Model
{
    use BelongsToTenant, HasFactory, HasUlids;

    protected $table = 'feedbacks';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'tipo',
        'mensagem',
        'url_origem',
    ];

    protected $casts = [
        'tipo' => TipoFeedback::class,
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
