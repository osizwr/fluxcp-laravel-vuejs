<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Throwable;

/**
 * Write the rows currently in a table out as a seeder.
 *
 * For moving a working setup somewhere else: the news somebody has written,
 * the wiki as it has been edited, the downloads page as it has been filled in.
 * Run it, commit the file, and `db:seed` rebuilds that state on another
 * machine.
 *
 * It is not a backup. It takes the rows it is pointed at and nothing else --
 * no schema, no foreign keys, no order of operations between tables -- so what
 * it produces restores content into a database that already has the shape to
 * hold it. `mysqldump` is the tool for a backup, and this is the tool for
 * "the demo data I set up on my laptop".
 *
 * ---------------------------------------------------------------------------
 * Why it refuses tables rather than redacting them
 * ---------------------------------------------------------------------------
 *
 * The file this writes lands in `database/seeders`, which is tracked by git.
 * rAthena keeps `login.user_pass` in cleartext, so dumping `login` would put
 * every player's password into version control -- and the person running the
 * command would have no reason to suspect it.
 *
 * Redacting the column instead was the first design and is worse: it produces
 * a seeder that silently creates accounts with no usable password, which is a
 * broken restore that looks like a working one. So a table carrying a secret
 * is refused by name, with the column named, and `--include-sensitive` is
 * there for somebody who has read that sentence and means it anyway.
 */
final class DumpSeedData extends Command
{
    protected $signature = 'panel:dump-seed
                            {--tables= : Tables to dump, comma separated. Repeatable}
                            {--preset= : A ready-made set: content}
                            {--class=SnapshotSeeder : Class name for the seeder}
                            {--connection= : Read from this connection instead of the default}
                            {--limit=5000 : Most rows to take from any one table}
                            {--include-sensitive : Dump tables holding credentials anyway}
                            {--force : Overwrite the seeder file if it exists}';

    protected $description = 'Write the rows in a table out as a seeder class';

    /**
     * Columns whose presence makes a table too dangerous to write to disk.
     *
     * Matched on the column name, because that is what the hazard travels
     * under: `user_pass` is rAthena's cleartext password column whatever
     * table it turns up in.
     *
     * Secrets only. Personal data -- an e-mail address, a birthdate -- is not
     * on this list and is not checked for, so a table full of it dumps
     * without complaint. Whether that belongs in your repository is a
     * question this command cannot answer for you.
     *
     * @var list<string>
     */
    private const SECRET_COLUMNS = [
        'user_pass',
        'password',
        'secret',
        'token',
        'remember_token',
        'web_auth_token',
    ];

    /**
     * The tables worth keeping when somebody says "my setup".
     *
     * Content an operator typed, and nothing that is a log, a session or a
     * credential. `vote_log` is deliberately absent: it is an audit trail of
     * who was paid what, and it means nothing on another machine.
     *
     * @var array<string, list<string>>
     */
    private const PRESETS = [
        'content' => [
            'cp_cmsnews',
            'cp_cmspages',
            'news_article_tags',
            'wiki_categories',
            'wiki_pages',
            'panel_settings',
        ],
    ];

    public function handle(ConnectionResolverInterface $resolver, Filesystem $files): int
    {
        $tables = $this->requestedTables();

        if ($tables === []) {
            $this->components->error(
                'Name at least one table: --tables=cp_cmsnews,wiki_pages, or --preset=content.',
            );

            return self::INVALID;
        }

        $class = Str::studly((string) $this->option('class'));

        if (preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $class) !== 1) {
            $this->components->error('The class name must be a plain PHP class name.');

            return self::INVALID;
        }

        $path = database_path('seeders/'.$class.'.php');

        if ($files->exists($path) && ! $this->option('force')) {
            $this->components->error("{$class} already exists. Pass --force to overwrite it.");

            return self::FAILURE;
        }

        $name = $this->option('connection') === null
            ? config('database.default')
            : (string) $this->option('connection');

        try {
            $connection = $resolver->connection((string) $name);
            $connection->getPdo();
        } catch (Throwable $e) {
            $this->components->error("Could not reach the '{$name}' connection: ".$e->getMessage());

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $dumped = [];

        foreach ($tables as $table) {
            $outcome = $this->readTable($connection, $table, $limit);

            if ($outcome === null) {
                return self::FAILURE;
            }

            [$rows, $key] = $outcome;

            $dumped[$table] = ['rows' => $rows, 'key' => $key];

            $this->components->twoColumnDetail($table, count($rows).' rows');
        }

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $this->render($class, (string) $name, $dumped));

        $this->newLine();
        $this->components->info("Wrote database/seeders/{$class}.php");
        $this->components->warn(
            'That file is tracked by git. Read it before committing: everything in '
            .'those tables is now in it.',
        );
        $this->line("  php artisan db:seed --class={$class}");

        return self::SUCCESS;
    }

    /**
     * The tables asked for, from --tables and --preset together.
     *
     * @return list<string>
     */
    private function requestedTables(): array
    {
        $named = (string) ($this->option('tables') ?? '');

        $tables = array_filter(array_map('trim', explode(',', $named)));

        $preset = $this->option('preset');

        if (is_string($preset) && isset(self::PRESETS[$preset])) {
            $tables = array_merge(self::PRESETS[$preset], $tables);
        } elseif (is_string($preset) && $preset !== '') {
            $this->components->warn(
                "No preset called '{$preset}'. Known: ".implode(', ', array_keys(self::PRESETS)).'.',
            );
        }

        return array_values(array_unique($tables));
    }

    /**
     * One table's rows, or null when it cannot or should not be read.
     *
     * A preset naming a table this installation does not have is skipped
     * rather than fatal -- `cp_cmspages` is absent until somebody installs the
     * schema -- while a table named explicitly is an error, because somebody
     * typed it and is owed the news that it is not there.
     *
     * @return array{0: list<array<string, mixed>>, 1: string|null}|null
     */
    private function readTable(ConnectionInterface $connection, string $table, int $limit): ?array
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1) {
            $this->components->error("'{$table}' is not a table name.");

            return null;
        }

        $schema = $connection->getSchemaBuilder();

        if (! $schema->hasTable($table)) {
            $this->components->warn("{$table}: no such table here, skipped.");

            return [[], null];
        }

        $columns = $schema->getColumnListing($table);

        $secrets = array_values(array_intersect(
            array_map('strtolower', $columns),
            self::SECRET_COLUMNS,
        ));

        if ($secrets !== [] && ! $this->option('include-sensitive')) {
            $this->components->error(
                "{$table} holds ".implode(' and ', $secrets)
                .'. Refusing to write that to a file in version control. '
                .'Pass --include-sensitive if you mean it.',
            );

            return null;
        }

        $query = $connection->table($table);

        /*
         * Ordered by the primary key where there is a single one, so two dumps
         * of an unchanged table are the same file and a commit shows what
         * actually changed rather than a reshuffle.
         */
        $key = $this->primaryKey($connection, $table, $columns);

        if ($key !== null) {
            $query->orderBy($key);
        }

        $rows = $query->limit($limit + 1)->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();

        if (count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);

            $this->components->warn(
                "{$table}: more than {$limit} rows; only the first {$limit} were taken. "
                .'Raise --limit, or use mysqldump for something this size.',
            );
        }

        /** @var list<array<string, mixed>> $rows */
        return [$rows, $key];
    }

    /**
     * The single-column primary key, when the table has one.
     *
     * Only a single column, because that is what `upsert` can key on. A
     * composite key falls back to insertOrIgnore in the generated file, which
     * is the honest behaviour: it adds what is missing and leaves what is
     * there.
     *
     * @param  list<string>  $columns
     */
    private function primaryKey(ConnectionInterface $connection, string $table, array $columns): ?string
    {
        try {
            $indexes = $connection->getSchemaBuilder()->getIndexes($table);
        } catch (Throwable) {
            return null;
        }

        foreach ($indexes as $index) {
            $isPrimary = (bool) ($index['primary'] ?? false);
            $on = (array) ($index['columns'] ?? []);

            if ($isPrimary && count($on) === 1 && in_array($on[0], $columns, true)) {
                return (string) $on[0];
            }
        }

        return null;
    }

    /**
     * The seeder file itself.
     *
     * @param  array<string, array{rows: list<array<string, mixed>>, key: string|null}>  $dumped
     */
    private function render(string $class, string $connection, array $dumped): string
    {
        $total = array_sum(array_map(fn (array $t): int => count($t['rows']), $dumped));
        $when = now()->toDayDateTimeString();

        $body = '';

        foreach ($dumped as $table => $taken) {
            $body .= $this->renderTable($table, $taken['rows'], $taken['key']);
        }

        $tables = implode(', ', array_keys($dumped));

        return <<<PHP
        <?php

        declare(strict_types=1);

        namespace Database\\Seeders;

        use Illuminate\\Database\\Seeder;
        use Illuminate\\Support\\Facades\\DB;

        /**
         * A snapshot, written by `php artisan panel:dump-seed`.
         *
         * {$total} rows from {$tables}, as they stood on {$when}.
         *
         * Rows are written with upsert where the table has a single-column
         * primary key, and insertOrIgnore where it does not, so running this
         * twice is not two copies. It restores content into a database that
         * already has the tables -- run the migrations and
         * `panel:install-schema` first.
         *
         * Generated. Edit the database and dump it again rather than editing
         * this by hand.
         */
        final class {$class} extends Seeder
        {
            /**
             * The connection this was taken from.
             *
             * Resolved by name at run time, so a machine whose server groups
             * are configured differently reads its own.
             */
            private const CONNECTION = '{$connection}';

            public function run(): void
            {
        {$body}    }
        }

        PHP;
    }

    /**
     * One table's block inside run().
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function renderTable(string $table, array $rows, ?string $key): string
    {
        if ($rows === []) {
            return "        // {$table}: no rows.\n";
        }

        $lines = '';

        foreach ($rows as $row) {
            $pairs = [];

            foreach ($row as $column => $value) {
                $pairs[] = "'".addcslashes((string) $column, "'\\")."' => ".$this->literal($value);
            }

            $lines .= '            ['.implode(', ', $pairs)."],\n";
        }

        $write = $key === null
            ? '->insertOrIgnore($rows);'
            : "->upsert(\$rows, ['{$key}']);";

        return <<<PHP
                \$rows = [
        {$lines}        ];

                DB::connection(self::CONNECTION)->table('{$table}')
                    {$write}

        PHP;
    }

    /**
     * One value, as PHP source.
     *
     * Binary goes through base64 rather than into a quoted string: a guild
     * emblem is a PNG, and a PNG pasted into a PHP file is a file nobody can
     * open and a diff nobody can read.
     */
    private function literal(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return var_export($value, true);
        }

        $value = (string) $value;

        if (! mb_check_encoding($value, 'UTF-8')) {
            return "base64_decode('".base64_encode($value)."')";
        }

        /*
         * A news body or a wiki page is several lines of Markdown, and a
         * single-quoted string cannot say "newline" -- it can only contain
         * one, which puts the rest of the row in column zero and turns every
         * later edit into a diff nobody can read. So anything carrying a
         * control character is written double-quoted and escaped, and
         * everything else keeps the quieter single quotes.
         */
        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            return '"'.$this->escaped($value).'"';
        }

        return "'".addcslashes($value, "'\\")."'";
    }

    /**
     * A string's contents, for between double quotes.
     *
     * `$` is escaped along with the obvious two, because PHP would otherwise
     * read `$body` in somebody's news post as a variable.
     */
    private function escaped(string $value): string
    {
        $escaped = strtr($value, [
            '\\' => '\\\\',
            '"' => '\\"',
            '$' => '\\$',
            "\n" => '\\n',
            "\r" => '\\r',
            "\t" => '\\t',
        ]);

        // Anything else below a space has no escape of its own and would be
        // invisible in the file.
        return (string) preg_replace_callback(
            '/[\x00-\x1F\x7F]/',
            static fn (array $m): string => sprintf('\\x%02X', ord($m[0])),
            $escaped,
        );
    }
}
