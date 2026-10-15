<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\UpdateDownloadSettingsRequest;
use App\Models\Account;
use App\Models\PanelSetting;
use App\Services\Settings\PanelSettings;
use App\Services\Theme\ClientBootstrap;
use Illuminate\Http\JsonResponse;

/**
 * The download page, as the dashboard edits it.
 *
 * Two keys are written -- the notice and the packages -- and both are read
 * back through ClientBootstrap, so the editor is looking at the same answer
 * the download page is rendered from rather than at its own reconstruction of
 * it. That is what keeps "save" and "see" in agreement.
 *
 * The system requirements and installation steps on the same page are
 * deliberately not here. They are longer-lived, nobody edits them between
 * client builds, and they are a table editor rather than a link editor; they
 * stay in config/game.php until there is a reason to move them.
 */
final class DownloadSettingsController
{
    /** The settings keys this screen owns. */
    private const KEYS = ['downloads.notice', 'downloads.clients'];

    public function __construct(
        private readonly PanelSettings $settings,
        private readonly ClientBootstrap $bootstrap,
    ) {}

    public function show(): JsonResponse
    {
        return $this->current();
    }

    public function update(UpdateDownloadSettingsRequest $request): JsonResponse
    {
        $settings = $request->settings();

        $account = $request->user();
        $editor = $account instanceof Account ? $account->account_id : null;

        /*
         * The notice is stored as an override even when it is null, so that
         * "the operator cleared the notice" is distinguishable from "nobody
         * has touched it" -- otherwise clearing it from the dashboard would
         * fall straight back to whatever GAME_DOWNLOADS_NOTICE still says and
         * the notice would reappear. Resetting the section is what removes
         * the override.
         */
        $this->settings->put('downloads.notice', $settings['notice'], $editor);
        $this->settings->put('downloads.clients', $settings['clients'], $editor);

        return $this->current();
    }

    /**
     * Drop the overrides, so the page goes back to config/game.php.
     *
     * A real undo rather than a guess: nothing here tries to reconstruct the
     * previous values, it simply stops overriding, and the file that was
     * always the default takes over again.
     */
    public function destroy(): JsonResponse
    {
        foreach (self::KEYS as $key) {
            $this->settings->forget($key);
        }

        return $this->current();
    }

    /**
     * What the download page would render right now, plus where it came from.
     *
     * The provenance is in `meta` because it changes what the screen should
     * say: an operator looking at values they did not recognise needs to know
     * whether they are reading their own config file or somebody's edit from
     * three months ago.
     */
    private function current(): JsonResponse
    {
        $downloads = $this->bootstrap->downloads();

        /** @var PanelSetting|null $record */
        $record = PanelSetting::query()
            ->whereIn('key', self::KEYS)
            ->orderByDesc('updated_at')
            ->first();

        return response()->json([
            'data' => [
                'notice' => $downloads['notice'],
                'clients' => $downloads['clients'],
            ],
            'meta' => [
                'platforms' => UpdateDownloadSettingsRequest::PLATFORMS,
                'overridden' => $record !== null,
                'updated_at' => $record?->updated_at?->toIso8601String(),
                'updated_by' => $record?->updated_by,
            ],
        ]);
    }
}
