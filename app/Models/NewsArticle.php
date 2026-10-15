<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesLoginConnection;
use App\Support\Rathena\ServerRegistry;
use Carbon\CarbonImmutable;
use Database\Factories\NewsArticleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A news entry: a row of `cp_cmsnews`.
 *
 * FluxCP's news CMS, which stored TinyMCE output in `body`. Panel-owned, but
 * kept in the login database where the legacy installer put it.
 *
 * Note what the legacy schema does *not* have: no category, no thumbnail, no
 * excerpt, no publish date distinct from `created`, and no draft flag. A news
 * block cannot therefore show a thumbnail without the panel inventing one, so
 * it does not. The excerpt is derived from the body, which is a presentation
 * concern computed from real content rather than a new column.
 *
 * The one thing that *has* been added is the tag -- the word beside the
 * headline -- and it is deliberately not a column here either. It lives in the
 * panel's own `news_article_tags`, joined on the id, so this table stays the
 * one FluxCP wrote and a legacy install reading it finds nothing new. See the
 * migration.
 *
 * @property int $id
 * @property string $title
 * @property string $body
 * @property string $link
 * @property string $author
 * @property CarbonImmutable|null $created
 * @property CarbonImmutable|null $modified
 */
final class NewsArticle extends Model
{
    /** @use HasFactory<NewsArticleFactory> */
    use HasFactory;

    use UsesLoginConnection;

    protected $table = 'cp_cmsnews';

    public $timestamps = false;

    /**
     * Deliberately empty.
     *
     * The news actions write through forceFill, with the column list spelled
     * out at each call site, because two of those columns are not the
     * editor's to set: `author` defaults to the account writing the article so
     * a byline cannot be forged, and `created` is left alone on an edit so
     * saving a typo does not reorder the front page. A fillable list would
     * make both of those a mass-assignment away.
     *
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'created' => 'immutable_datetime',
            'modified' => 'immutable_datetime',
        ];
    }

    /**
     * The tag row for this article, if it has been given one.
     *
     * A relation across two connections, which Eloquent handles because it
     * never joins: the related model is queried on its own connection with
     * the ids it was asked about. Eager-loading it is therefore one extra
     * query for a page of articles rather than one per article, and the
     * listings do exactly that.
     *
     * Scoped to the current server group here rather than in a global scope,
     * because which group is current is a property of the request and a
     * global scope would bake the first one resolved into the process.
     *
     * @return HasOne<NewsArticleTag, $this>
     */
    public function tagRow(): HasOne
    {
        return $this->hasOne(NewsArticleTag::class, 'article_id')
            ->where('server_group', app(ServerRegistry::class)->current()->key);
    }

    /** The tag as the editor typed it, or null for an article without one. */
    public function tagName(): ?string
    {
        return $this->tagRow?->tag;
    }

    /**
     * Newest first, which is the only ordering the schema supports.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeNewestFirst(Builder $query): void
    {
        $query->orderByDesc('created')->orderByDesc('id');
    }

    /**
     * A plain-text summary of the body.
     *
     * The body is HTML from a rich-text editor, so tags are stripped before
     * truncating -- cutting HTML at a character count produces unbalanced
     * markup, and rendering a stored-HTML fragment unescaped in a list is how
     * a news CMS becomes an XSS vector.
     */
    public function excerpt(int $characters = 180): string
    {
        $text = html_entity_decode(strip_tags($this->body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) <= $characters
            ? $text
            : rtrim(mb_substr($text, 0, $characters)).'…';
    }
}
