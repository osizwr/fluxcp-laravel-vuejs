<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesLoginConnection;
use Carbon\CarbonImmutable;
use Database\Factories\NewsArticleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A news entry: a row of `cp_cmsnews`.
 *
 * FluxCP's news CMS, which stored TinyMCE output in `body`. Panel-owned, but
 * kept in the login database where the legacy installer put it.
 *
 * Note what the legacy schema does *not* have: no category, no thumbnail, no
 * excerpt, no publish date distinct from `created`, and no draft flag. A news
 * block cannot therefore show a category or a thumbnail without the panel
 * inventing one, so it does not. The excerpt is derived from the body, which is
 * a presentation concern computed from real content rather than a new column.
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
     * Written only through the admin news actions, which are not yet built.
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
