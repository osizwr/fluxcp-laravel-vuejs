<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The tag on one news article: a row of `news_article_tags`.
 *
 * On the panel's own connection, not the login database the article itself
 * lives in. See the migration for why that is, and for why the row is scoped
 * by server group.
 *
 * @property int $id
 * @property string $server_group
 * @property int $article_id
 * @property string $tag
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class NewsArticleTag extends Model
{
    protected $table = 'news_article_tags';

    /**
     * The panel's own connection, whatever a relation was told.
     *
     * Eloquent pushes the parent's connection onto a related model when it
     * builds a relation, and the parent is a NewsArticle on rAthena's login
     * database -- where this table does not exist and must not be created.
     * Returning null here ignores that and resolves the default connection,
     * which is the one the migration ran on.
     */
    public function getConnectionName(): ?string
    {
        return null;
    }

    /** @var list<string> */
    protected $fillable = ['server_group', 'article_id', 'tag'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'article_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
