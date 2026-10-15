<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A wiki section written from the dashboard: a row of `wiki_categories`.
 *
 * Laid over the `_category.md` on disk rather than replacing it. See the
 * migration for the whole arrangement, and
 * App\Services\Content\WikiCategoryOverrides for how a row reaches the wiki.
 *
 * @property string $slug
 * @property string $title
 * @property string|null $description
 * @property string|null $icon
 * @property string|null $image
 * @property int|null $sort_order
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class WikiCategory extends Model
{
    protected $table = 'wiki_categories';

    protected $primaryKey = 'slug';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'slug',
        'title',
        'description',
        'icon',
        'image',
        'sort_order',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
