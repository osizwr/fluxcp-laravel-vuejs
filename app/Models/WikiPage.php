<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A wiki page written from the dashboard: a row of `wiki_pages`.
 *
 * Laid over the Markdown on disk rather than replacing it. See the migration
 * for the whole arrangement, and App\Services\Content\WikiOverrides for how a
 * row reaches the wiki.
 *
 * @property string $path
 * @property string $category
 * @property string $slug
 * @property string $title
 * @property string|null $summary
 * @property int|null $sort_order
 * @property string|null $body
 * @property bool $hidden
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class WikiPage extends Model
{
    protected $table = 'wiki_pages';

    protected $primaryKey = 'path';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = [
        'path',
        'category',
        'slug',
        'title',
        'summary',
        'sort_order',
        'body',
        'hidden',
        'updated_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'hidden' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
