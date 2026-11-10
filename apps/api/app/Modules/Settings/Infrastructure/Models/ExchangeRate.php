<?php

namespace App\Modules\Settings\Infrastructure\Models;

use App\Modules\Settings\Database\Factories\ExchangeRateFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tabla exchange_rates · CFG-7. Un tipo de cambio por par y fecha; no se edita: se publica otro.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $from_currency
 * @property string $to_currency
 * @property string $rate_date
 * @property string $rate
 * @property string|null $source
 * @property Carbon|null $created_at
 */
class ExchangeRate extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<ExchangeRateFactory> */
    use HasFactory;

    use HasUuids;

    protected $table = 'exchange_rates';

    protected $guarded = ['id', 'tenant_id'];

    protected static function newFactory(): ExchangeRateFactory
    {
        return ExchangeRateFactory::new();
    }
}
