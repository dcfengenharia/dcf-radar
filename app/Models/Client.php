<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Client extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;

    protected $fillable = [
        'tenant_id',
        'name',
        'trading_name',
        'cnpj',
        'logo_path',
        'email',
        'phone',
    ];

    public function getLogoUrlAttribute(): string
    {
        if ($this->logo_path && Storage::disk('public')->exists($this->logo_path)) {
            return Storage::disk('public')->url($this->logo_path);
        }

        return asset('assets/img/logos/logo_oficial.png');
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function works(): HasMany
    {
        return $this->hasMany(Work::class);
    }
}
