<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesLoginConnection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An operator-authored page: a row of `cp_cmspages`.
 *
 * Reached by its `path` rather than its id, so a page lives at a stable URL
 * that survives being edited and does not expose how many pages exist.
 *
 * @property int $id
 * @property string $path
 * @property string $title
 * @property string $body
 * @property CarbonImmutable|null $modified
 */
final class StaticPage extends Model
{
    use UsesLoginConnection;

    protected $table = 'cp_cmspages';

    /**
     * The legacy table has only `modified`, with no `created`, so Laravel's
     * timestamp handling is off and the column is set explicitly on write.
     */
    public $timestamps = false;

    /**
     * Written only through the controller, which validates and normalises the
     * path first.
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
            'modified' => 'immutable_datetime',
        ];
    }

    public function scopeAtPath(Builder $query, string $path): void
    {
        $query->where('path', self::normalisePath($path));
    }

    /**
     * A path reduced to its canonical form.
     *
     * Lower-cased and stripped of surrounding slashes, so `/Rules/`, `rules`
     * and `Rules` are one page rather than three — the legacy stored whatever
     * was typed and looked it up with an exact match, which made a page
     * unreachable if its link and its row disagreed about the slash.
     */
    public static function normalisePath(string $path): string
    {
        return mb_strtolower(trim(trim($path), '/'));
    }
}
