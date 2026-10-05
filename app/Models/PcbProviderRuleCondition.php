<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PcbProviderRuleCondition extends Model
{
    use HasFactory;

    protected $table = 'pcb_provider_rule_conditions';

    protected $fillable = [
        'rule_id',
        'field',
        'operator',
        'value',
        'sort_order',
    ];

    protected $casts = [
        'value' => 'json',
        'sort_order' => 'integer',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(PcbProviderRule::class, 'rule_id');
    }
}
