<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The word beside a headline: News, Update, Event, Auction.
 *
 * It is not a column of `cp_cmsnews`, and cannot be. That table is FluxCP's,
 * reproduced byte for byte by App\Support\Rathena\Schema\PanelSchema so that a
 * panel and a legacy install can share one database, and `panel:install-schema`
 * only ever creates tables that are missing -- it does not alter one that is
 * already there. A column added to the definition would therefore reach new
 * installs and no existing one, which is the worst of both.
 *
 * So the tag is kept here, beside the panel's own data, and joined on the
 * article's id. An article nobody has tagged has no row, which is the same
 * arrangement the wiki overrides use: absence means "as it was before this
 * table existed".
 *
 * Scoped by server group, because the id is only unique within one. A panel
 * fronting two groups has two `cp_cmsnews` tables, both counting from 1, and a
 * tag keyed on the id alone would show the first group's word beside the
 * second group's headline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_article_tags', function (Blueprint $table): void {
            /*
             * A surrogate key with the real one as a unique index. The pair is
             * what identifies a row, but Eloquent addresses a model by a
             * single key, and `updateOrCreate` on the pair is this table's
             * only write.
             */
            $table->id();

            /* The group's key from config/rathena.php, not its display name. */
            $table->string('server_group', 64);

            /*
             * No foreign key: the article is in rAthena's login database on
             * another connection. The controller deletes the row with the
             * article instead -- ids are reused after a restart, and a tag
             * left behind would surface on somebody else's headline.
             */
            $table->unsignedInteger('article_id');

            /*
             * As the editor typed it. Not a slug and not an enum: the panel
             * cannot know what a server calls its posts, and an operator who
             * wants "Guild Week" should get "Guild Week" rather than the
             * nearest thing a list of five allowed values has to offer. The
             * front end recognises the common ones and gives them a colour; a
             * word it does not know is drawn plainly.
             */
            $table->string('tag', 32);

            $table->timestamps();

            $table->unique(['server_group', 'article_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_article_tags');
    }
};
