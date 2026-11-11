<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class LaborRegime extends Model
{
    use HasUuids; // Laravel 12 genera UUID v7 (ADR 0001)

    protected $fillable = ['code', 'country_code', 'name', 'kind'];
}
