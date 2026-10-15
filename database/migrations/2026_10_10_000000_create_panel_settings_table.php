<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The settings an administrator may change from the dashboard.
 *
 * Everything this table holds already has a default in config/game.php, and a
 * key absent from here means "whatever the file says". That is deliberate: an
 * install that never opens the dashboard behaves exactly as it did before the
 * table existed, and an operator who edits the file still has their edit
 * honoured for every key nobody has overridden.
 *
 * It lives on the panel's own connection rather than beside `cp_cmsnews` in
 * the login database, because none of it is rAthena's data and a panel
 * fronting two server groups wants one set of download links, not two.
 *
 * `value` is JSON rather than a string: the things worth editing here are
 * lists of packages and their mirrors, not scalars, and a column of serialised
 * PHP is a column nothing else can read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel_settings', function (Blueprint $table): void {
            /*
             * The key is the primary key. There is no surrogate id because
             * there is never a reason to address a setting by anything other
             * than its name, and a unique index on the name would be the same
             * index with an extra column bolted beside it.
             *
             * 191 rather than 255 so the index fits in the 767-byte limit on
             * utf8mb4 under the older MySQL row formats an operator may still
             * be running.
             */
            $table->string('key', 191)->primary();

            /*
             * Nullable, because null is a value somebody chose rather than the
             * absence of one. An operator who clears the download notice from
             * the dashboard means "no notice" -- and if that were stored as no
             * row, the next request would fall straight back to whatever
             * GAME_DOWNLOADS_NOTICE still says and the notice they just removed
             * would be back on the page.
             *
             * So the row existing is what "overridden" means, and its value is
             * free to be null. Resetting a section deletes the row; it does not
             * null it.
             */
            $table->json('value')->nullable();

            /*
             * Who last wrote it, for the audit question "who changed the
             * download link". Nullable and deliberately *not* a foreign key:
             * accounts live in rAthena's database on another connection, so
             * the constraint cannot be expressed, and a deleted account must
             * not take the history of what it changed with it.
             */
            $table->unsignedInteger('updated_by')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_settings');
    }
};
