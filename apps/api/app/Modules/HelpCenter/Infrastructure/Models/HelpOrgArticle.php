<?php

namespace App\Modules\HelpCenter\Infrastructure\Models;

use App\Modules\HelpCenter\Database\Factories\HelpOrgArticleFactory;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * AYU-4 · Artículo o política de la organización (kb_articles): borrador de trabajo con audiencia,
 * responsable y fecha de revisión; lo que ven los usuarios es la última versión publicada (kb_article_versions).
 *
 * @property string $id
 * @property string|null $kb_category_id
 * @property string $title
 * @property string $slug
 * @property string $body
 * @property string $locale
 * @property string $status
 * @property array<string, mixed>|null $audience
 * @property string|null $owner_user_id
 * @property string|null $review_on
 * @property int $version
 * @property int $lock_version
 * @property Carbon|null $published_at
 */
class HelpOrgArticle extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<HelpOrgArticleFactory> */
    use HasFactory;

    use HasUuids;
    use SoftDeletes;

    protected $table = 'kb_articles';

    protected $guarded = ['id', 'tenant_id', 'search_vector'];

    protected $attributes = ['status' => 'draft', 'locale' => 'es', 'version' => 1];

    protected $casts = ['audience' => 'array', 'version' => 'integer', 'lock_version' => 'integer', 'published_at' => 'datetime'];

    protected static function newFactory(): HelpOrgArticleFactory
    {
        return HelpOrgArticleFactory::new();
    }
}
