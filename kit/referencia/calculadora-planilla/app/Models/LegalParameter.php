<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasVigencia;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Parámetro legal con vigencia. Un cambio de norma es una fila nueva, nunca una edición (RN-PAR-02). */
final class LegalParameter extends Model
{
    use HasUuids, HasVigencia;

    protected $fillable = ['country_code', 'labor_regime_code', 'code', 'name', 'value_numeric', 'value_json', 'unit', 'currency',
        'valid_from', 'valid_to', 'legal_source', 'verified', 'notes'];

    protected function casts(): array
    {
        return ['value_json' => 'array', 'valid_from' => 'date', 'valid_to' => 'date', 'verified' => 'boolean'];
    }
}
