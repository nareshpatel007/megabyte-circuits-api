<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PcbProviderRule extends Model
{
    use HasFactory;

    protected $table = 'pcb_provider_rules';

    protected $fillable = [
        'name',
        'slug',
        'provider',
        'priority',
        'match_type',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'priority' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function conditions(): HasMany
    {
        return $this->hasMany(PcbProviderRuleCondition::class, 'rule_id')->orderBy('sort_order', 'asc');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('priority', 'asc')->orderBy('sort_order', 'asc')->orderBy('id', 'asc');
    }
}
