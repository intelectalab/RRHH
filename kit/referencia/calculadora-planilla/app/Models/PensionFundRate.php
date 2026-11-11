<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasVigencia;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PensionFundRate extends Model
{
    use HasUuids, HasVigencia;

    protected $fillable = ['pension_fund_id', 'contribution_rate', 'commission_flow_rate', 'commission_mixed_rate',
        'insurance_premium_rate', 'max_insurable_remuneration', 'source', 'verified', 'valid_from', 'valid_to'];

    protected function casts(): array
    {
        return ['valid_from' => 'date', 'valid_to' => 'date', 'verified' => 'boolean'];
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(PensionFund::class, 'pension_fund_id');
    }
}
