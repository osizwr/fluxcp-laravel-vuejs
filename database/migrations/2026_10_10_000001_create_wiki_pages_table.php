<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wiki pages written from the dashboard, laid over the ones on disk.
 *
 * config/wiki.php argues at some length for keeping the guide in Markdown
 * files: version control, review before deploy, and a wrong edit being a
 * revert rather than an apology. All of that is still true, and none of it is
 * given up here -- the files remain the wiki, and a page nobody has edited in
 * the browser is still read from disk exactly as before.
 *
 * What that argument also names is the operator this arrangement serves badly:
 * the one whose only access is a web browser. This table is for them. A row is
 * an edit laid over the file of the same path, a page that has no file at all,
 * or a tombstone hiding one -- and deleting the row puts the file back,
 * untouched, because the file was never written to.
 *
 * The honest trade, written down so nobody discovers it later: a page edited
 * here is no longer in version control, and a deploy will not update it. The
 * dashboard says so on the page it says it about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wiki_pages', function (Blueprint $table): void {
            /*
             * `category/slug`, the same string the wiki is addressed by, so
             * an override and the file it overrides are matched on the URL
             * rather than on anything derived.
             */
            $table->string('path', 191)->primary();

            $table->string('category', 96);
            $table->string('slug', 96);

            $table->string('title', 191);
            $table->string('summary', 500)->nullable();

            /*
             * Named for what it does rather than `order`, which is a reserved
             * word in every database this panel supports and a quoting mistake
             * waiting to happen. Null sorts last, as it does on disk.
             */
            $table->integer('sort_order')->nullable();

            /** Markdown, as typed. Rendered on the way out, never on the way in. */
            $table->longText('body')->nullable();

            /*
             * A page removed from the wiki.
             *
             * A flag rather than a deleted row, because the row is the only
             * thing standing between a file on disk and the page it draws: for
             * a file-backed page, deleting the row is the undo, so deletion
             * cannot also be how removal is expressed.
             */
            $table->boolean('hidden')->default(false);

            /*
             * Nullable and not a foreign key: accounts live in rAthena's
             * database on another connection, and a deleted account must not
             * take the record of what it wrote with it.
             */
            $table->unsignedInteger('updated_by')->nullable();

            $table->timestamps();

            // The index listing groups by section, which is the one query
            // shape this table is read by other than "everything".
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wiki_pages');
    }
};
