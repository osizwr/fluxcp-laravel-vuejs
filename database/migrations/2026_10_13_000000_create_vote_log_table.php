<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every vote this panel has started, and which of them paid out.
 *
 * A row is written when a player presses Vote -- before they have voted,
 * before anything is owed -- because the row is what makes the callback that
 * comes back later provable. See config/vote.php for the whole journey.
 *
 * It is also the audit trail, which is the other half of its job: "why does
 * this account have four hundred credits" has an answer here, with the time
 * and the address each one arrived from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vote_log', function (Blueprint $table): void {
            $table->id();

            /*
             * Nullable and not a foreign key: accounts live in rAthena's
             * database on another connection, and a deleted account must not
             * take the record of what was paid out with it.
             */
            $table->unsignedInteger('account_id')->nullable();

            /** The key from config/vote.php, not the site's display name. */
            $table->string('site', 64);

            /*
             * The single-use value handed to the listing site and quoted back
             * by it. Unique, because the whole point of it is that a second
             * callback carrying the same one is a replay rather than a second
             * vote.
             */
            $table->string('reference', 64)->unique();

            /** What this vote paid. Zero until it is confirmed. */
            $table->unsignedInteger('credits')->default(0);

            /*
             * When the callback arrived. Null means it never did -- the
             * player opened the site and closed it, or the site never called
             * back -- which is an ordinary outcome and not an error.
             */
            $table->timestamp('claimed_at')->nullable();

            /** Who started it, and who the site said voted. Both kept for the
             *  audit trail; neither is ever what decides the payout. */
            $table->string('started_ip', 45)->nullable();
            $table->string('voter_ip', 45)->nullable();

            $table->timestamps();

            /*
             * The cooldown question -- "has this account claimed this site
             * recently" -- is the one query this table answers on every page
             * load, so it is the one index it carries.
             */
            $table->index(['account_id', 'site', 'claimed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vote_log');
    }
};
