<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\UsesPanelConnection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * The panel's own credential record for an rAthena account.
 *
 * This exists because rAthena's `login.user_pass` cannot be used as a modern
 * credential: it is a varchar(32) holding cleartext or unsalted MD5, and the
 * emulator's login server reads it directly, so the panel may neither widen
 * nor re-hash it. Instead the panel keeps its own properly hashed copy here
 * and verifies against it, falling back to the rAthena comparison only for
 * accounts that have not signed in since the migration.
 *
 * See docs/MIGRATION_DECISIONS.md (D1).
 *
 * @property int $id
 * @property string $server_group
 * @property int $account_id
 * @property string $password_hash
 * @property string|null $remember_token
 */
final class PanelCredential extends Model
{
    /** @use HasFactory<\Database\Factories\PanelCredentialFactory> */
    use HasFactory;
    use UsesPanelConnection;

    protected $table = 'panel_credentials';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'server_group',
        'account_id',
        'password_hash',
        'remember_token',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password_hash',
        'remember_token',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'account_id' => 'integer',
            'password_hash' => 'hashed',
        ];
    }
}
