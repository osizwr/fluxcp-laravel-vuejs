<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The panel has no `users` table.
 *
 * Accounts are rAthena's, in its own `login` table on its own connection, so
 * the framework's default users and password_reset_tokens tables would be
 * dead weight. Only the session store is needed here.
 *
 * The column is still called `user_id` because Laravel's database session
 * handler writes the authenticated identifier under that name; the value is
 * an rAthena account_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
