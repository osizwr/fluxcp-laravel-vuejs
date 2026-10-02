<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * The panel owns almost no data of its own.
 *
 * Accounts, characters and guilds belong to rAthena and must never be seeded
 * into a real server's database, so there is deliberately nothing here that
 * writes to those tables. Test fixtures are built by the model factories
 * against a disposable database instead.
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        //
    }
}
