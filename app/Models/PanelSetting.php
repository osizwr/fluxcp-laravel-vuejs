<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One overridden setting: a row of `panel_settings`.
 *
 * Read and written through App\Services\Settings\PanelSettings rather than
 * directly, so that the fallback to config/game.php happens in one place and
 * no caller has to remember that an absent row is not an empty value.
 *
 * @property string $key
 * @property mixed $value
 * @property int|null $updated_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
final class PanelSetting extends Model
{
    protected $table = 'panel_settings';

    protected $primaryKey = 'key';

    protected $keyType = 'string';

    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = ['key', 'value', 'updated_by'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            /*
             * Cast rather than accessor so that a list of packages comes back
             * as an array and goes in as JSON without either side encoding it
             * by hand -- which is where a double-encoded column comes from.
             */
            'value' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
