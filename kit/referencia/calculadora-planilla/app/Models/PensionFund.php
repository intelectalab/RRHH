<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class PensionFund extends Model
{
    use HasUuids;

    protected $fillable = ['code', 'country_code', 'name', 'kind'];

    public function rates(): HasMany
    {
        return $this->hasMany(PensionFundRate::class);
    }
}
