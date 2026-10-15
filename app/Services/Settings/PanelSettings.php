<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Models\PanelSetting;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * What the dashboard has changed, over what the configuration files say.
 *
 * There are two sources for every one of these settings and they are not
 * equals. config/game.php is the default: it is what an operator wrote, it is
 * in version control, and it is what a fresh install runs on. This table holds
 * only the keys somebody has since changed from the dashboard, and a key that
 * is not in it is not "empty" -- it means nobody has overridden the file.
 *
 * Keeping the two apart is what makes the dashboard safe to adopt. Editing one
 * download link does not flatten the rest of the file into the database, an
 * operator's later edit to a key nobody touched still takes effect, and
 * `forget()` is a real undo rather than a guess at what the value used to be.
 *
 * Reads are memoised for the life of the request. The whole table is a handful
 * of rows -- one per overridden key -- so it is fetched once rather than
 * queried per lookup, and ClientBootstrap asking for four keys costs one
 * query.
 */
final class PanelSettings
{
    /**
     * Every override, keyed by setting name, or null before the first read.
     *
     * @var array<string, mixed>|null
     */
    private ?array $overrides = null;

    public function __construct(private readonly ConfigRepository $config) {}

    /**
     * The overridden value for a key.
     *
     * Null is ambiguous on its own: it is both "nobody has overridden this"
     * and "somebody overrode it to nothing", and both are real states -- an
     * operator who clears the download notice means it to stay cleared, not to
     * fall back to the config file. What tells them apart is whether the row
     * exists, which is has(). Nearly every caller wants resolve() instead.
     */
    public function get(string $key): mixed
    {
        return $this->overrides()[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->overrides());
    }

    /**
     * The value in force: the override if there is one, the configured
     * default otherwise.
     *
     * This is the method almost everything should call. $configKey is spelled
     * out rather than derived from $key because the two namespaces are not the
     * same shape -- 'downloads.clients' here is 'game.downloads.clients' in the
     * config -- and a derived key is a rename away from silently resolving to
     * nothing.
     */
    public function resolve(string $key, string $configKey, mixed $default = null): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }

        return $this->config->get($configKey, $default);
    }

    /**
     * Override a key, recording who did it.
     *
     * @throws Throwable
     */
    public function put(string $key, mixed $value, ?int $updatedBy = null): void
    {
        PanelSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_by' => $updatedBy],
        );

        $this->flush();
    }

    /**
     * Drop an override, so the key falls back to config/game.php again.
     */
    public function forget(string $key): void
    {
        PanelSetting::query()->whereKey($key)->delete();

        $this->flush();
    }

    /**
     * Forget what was read this request.
     *
     * Called after every write so a controller that saves and then renders the
     * result does not hand back the values it just replaced.
     */
    public function flush(): void
    {
        $this->overrides = null;
    }

    /**
     * @return array<string, mixed>
     */
    private function overrides(): array
    {
        if ($this->overrides !== null) {
            return $this->overrides;
        }

        try {
            return $this->overrides = PanelSetting::query()
                ->pluck('value', 'key')
                ->all();
        } catch (QueryException $e) {
            /*
             * The table is missing, which on this path means the migration has
             * not been run yet -- an install part-way through setup, or an
             * upgrade where `php artisan migrate` is still to come.
             *
             * Every setting has a configured default, so the honest answer is
             * "no overrides" and the site renders exactly as it did before this
             * feature existed. Failing instead would take down the front page
             * of a server whose only mistake was upgrading in two steps, which
             * is a far worse outcome than a dashboard that cannot save yet.
             *
             * Logged, not swallowed: a genuine connection fault reaches the
             * same catch, and that one an operator needs to see.
             */
            Log::warning('Panel settings could not be read; falling back to configuration.', [
                'exception' => $e->getMessage(),
            ]);

            return $this->overrides = [];
        }
    }
}
