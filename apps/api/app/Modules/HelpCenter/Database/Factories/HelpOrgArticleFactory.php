<?php

namespace App\Modules\HelpCenter\Database\Factories;

use App\Modules\HelpCenter\Infrastructure\Models\HelpOrgArticle;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<HelpOrgArticle>
 */
class HelpOrgArticleFactory extends Factory
{
    protected $model = HelpOrgArticle::class;

    public function definition(): array
    {
        $title = 'Política '.Str::random(6);

        return ['title' => $title, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)), 'body' => 'Texto de la política.', 'locale' => 'es', 'status' => 'draft', 'audience' => []];
    }
}
