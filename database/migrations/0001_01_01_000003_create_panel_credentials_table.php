<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The panel's own hashed credentials for rAthena accounts.
 *
 * rAthena's login.user_pass is a varchar(32) holding cleartext or unsalted
 * MD5, and the emulator's login server reads it directly, so the panel can
 * neither widen it nor re-hash it. It keeps a properly hashed copy here
 * instead and verifies against that, falling back to the rAthena comparison
 * only for accounts that have not signed in since the migration.
 *
 * See docs/MIGRATION_DECISIONS.md (D1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel_credentials', function (Blueprint $table): void {
            $table->id();

            /*
             * An account_id is only unique within its own server group: a
             * panel fronting two unrelated rAthena installations will see the
             * same ids in both.
             */
            $table->string('server_group', 64);
            $table->unsignedInteger('account_id');

            /*
             * Long enough for bcrypt (60) and Argon2id (about 96), with room
             * for a future algorithm. This is the whole point of the table:
             * rAthena's own column cannot hold any of them.
             */
            $table->string('password_hash', 255);

            $table->string('remember_token', 100)->nullable();

            $table->timestamps();

            $table->unique(['server_group', 'account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_credentials');
    }
};
