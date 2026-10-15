<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wiki sections written from the dashboard, laid over the ones on disk.
 *
 * The companion to `wiki_pages`, and the same arrangement for the same reason.
 * A section is a directory with a `_category.md` in it, and that file holds the
 * four things a section is: its title, its description, its icon and where it
 * sorts. An operator whose only access is a web browser could already write
 * the pages of a section -- creating one by naming a section nobody had used
 * yet -- and then had no way to say what that section was called. It appeared
 * titled from its own slug, and stayed that way.
 *
 * A row here is that missing sentence: the metadata for a section, whether or
 * not a directory backs it. Deleting the row puts the `_category.md` back in
 * charge, untouched, because it was never written to.
 *
 * Pages are not in here. A section's row says what the section is called and
 * nothing about what is in it: the pages of a section are rows of
 * `wiki_pages`, addressed by `category/slug`, and the two tables meet only on
 * that slug. A section with no pages in it is therefore not yet on the wiki at
 * all -- the index drops every empty section -- and the dashboard says so
 * beside the ones in that state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_categories', function (Blueprint $table): void {
            /*
             * The first segment of a page's URL, which is the only name a
             * section has. Matched against the directory on disk by this
             * string and nothing derived, exactly as `wiki_pages.path` is
             * matched against a file.
             */
            $table->string('slug', 96)->primary();

            $table->string('title', 191);

            /** The line under the section's name on the wiki's front page. */
            $table->string('description', 500)->nullable();

            /*
             * One of the names WikiIcon draws, not a path or a URL. The set is
             * fixed on purpose -- see that component -- and the save request
             * holds the same list, so a name that would fall back to the book
             * is refused at the point somebody picks it rather than quietly
             * becoming a different icon.
             */
            $table->string('icon', 32)->nullable();

            /*
             * The URL of the section's own art, as it goes into an <img src>.
             *
             * A path rather than a file reference, and a narrow one: the save
             * request only accepts a URL this panel's own uploader produced,
             * which is what stops the index being pointed at somebody else's
             * server. See WikiImages::isSectionImage().
             *
             * Null is the ordinary case and not a missing value -- a section
             * without art gets the placeholder the index already draws.
             */
            $table->string('image', 191)->nullable();

            /*
             * Named for what it does rather than `order`, which is a reserved
             * word in every database this panel supports. Null sorts last, as
             * it does on disk.
             */
            $table->integer('sort_order')->nullable();

            /*
             * There is no `hidden` column here, and `wiki_pages` has one.
             *
             * That table needs it because for a file-backed page the row is
             * the only thing standing between a reader and the file, so
             * deleting the row is the undo and removal has to be said some
             * other way. A section is not like that: a section is its pages,
             * and the wiki already drops any section with nothing in it. So
             * taking a section off the wiki is removing its pages -- which is
             * a tombstone each, and reversible one at a time -- and a row here
             * only ever says what a section is called.
             *
             * Which leaves one verb for this table rather than two: dropping
             * the row. On a section with a directory that hands `_category.md`
             * back its title; on one written here it forgets a name nothing
             * else was using.
             */

            /*
             * Nullable and not a foreign key: accounts live in rAthena's
             * database on another connection, and a deleted account must not
             * take the record of what it wrote with it.
             */
            $table->unsignedInteger('updated_by')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_categories');
    }
};
