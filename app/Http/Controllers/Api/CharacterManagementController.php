<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Enums\CharacterActionResult;
use App\Http\Resources\CharacterResource;
use App\Models\Account;
use App\Models\Character;
use App\Services\Rathena\CharacterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Looking at and maintaining a character.
 *
 * Ports modules/character/view.php, changeslot.php, resetlook.php,
 * resetpos.php, divorce.php, prefs.php and mapstats.php.
 *
 * ---------------------------------------------------------------------------
 * Who may act on which character
 * ---------------------------------------------------------------------------
 *
 * The legacy rule, kept: a player may always act on their own character, and
 * acting on somebody else's needs the matching ability. FluxCP wrote that as
 * `$char->account_id != $session->account->account_id && !$auth->allowedToX`
 * at the top of each action, and it is `authorise()` here so that one of the
 * seven cannot quietly be written without it.
 */
final class CharacterManagementController
{
    public function __construct(private readonly CharacterService $characters) {}

    /**
     * One character.
     */
    public function show(Request $request, Character $character): JsonResponse
    {
        $this->authorise($request, $character, 'ViewCharacter');

        return CharacterResource::make($character->load('guild'))
            ->additional(['meta' => [
                'preferences' => $this->characters->preferences($character),
            ]])
            ->response();
    }

    /**
     * Move a character to a different slot.
     *
     * @throws ValidationException
     */
    public function changeSlot(Request $request, Character $character): JsonResponse
    {
        $this->authorise($request, $character, 'ChangeSlot');

        $request->validate(['slot' => ['required', 'integer', 'min:1', 'max:99']]);

        return $this->respond(
            $this->characters->changeSlot($character, $request->integer('slot')),
            $character,
        );
    }

    public function resetLook(Request $request, Character $character): JsonResponse
    {
        $this->authorise($request, $character, 'ResetLook');

        return $this->respond($this->characters->resetLook($character), $character);
    }

    public function resetPosition(Request $request, Character $character): JsonResponse
    {
        $this->authorise($request, $character, 'ResetPosition');

        return $this->respond($this->characters->resetPosition($character), $character);
    }

    public function divorce(Request $request, Character $character): JsonResponse
    {
        $this->authorise($request, $character, 'DivorceCharacter');

        return $this->respond($this->characters->divorce($character), $character);
    }

    /**
     * Read or write a character's preferences.
     *
     * @throws ValidationException
     */
    public function preferences(Request $request, Character $character): JsonResponse
    {
        $this->authorise($request, $character, 'ModifyCharPrefs');

        if ($request->isMethod('GET')) {
            return response()->json(['data' => $this->characters->preferences($character)]);
        }

        $rules = [];

        foreach (CharacterService::preferenceNames() as $name) {
            $rules[$name] = ['sometimes', 'boolean'];
        }

        $submitted = $request->validate($rules);

        /*
         * Hiding from the zeny ladder is its own ability, because an operator
         * may want the ladder to be complete. Dropped from the submission
         * rather than refused, so the other preferences still save.
         */
        if (array_key_exists('HideFromZenyRanking', $submitted)
            && $request->user()?->cannot('HideFromZenyRank')) {
            unset($submitted['HideFromZenyRanking']);
        }

        return response()->json([
            'data' => $this->characters->setPreferences($character, $submitted),
            'message' => 'Preferences saved.',
        ]);
    }

    /**
     * How many characters are on each map.
     *
     * Public, as in the legacy panel. Characters who have asked to hide their
     * map are counted on no map rather than being findable by elimination.
     */
    public function mapStatistics(): JsonResponse
    {
        $rows = $this->characters->mapStatistics(
            (int) config('panel.characters.map_statistics_limit', 50),
        );

        return response()->json([
            'data' => $rows->map(fn (object $row): array => [
                'map' => (string) $row->map,
                'players' => (int) $row->players,
            ])->all(),
            'meta' => ['total_online' => (int) $rows->sum('players')],
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Authorisation and responses
    |--------------------------------------------------------------------------
    */

    /**
     * A player may act on their own character; anyone else needs the ability.
     */
    private function authorise(Request $request, Character $character, string $ability): void
    {
        $account = $request->user();

        abort_unless($account instanceof Account, 401);

        if ((int) $character->account_id === (int) $account->account_id) {
            return;
        }

        abort_unless($account->can($ability), 403, 'That is not your character.');
    }

    /**
     * Turn an outcome into a response.
     *
     * A refusal is a 422 with the reason, not a 500 and not a silent 200.
     * Several of these -- "the character is online", "that map does not allow
     * it" -- are ordinary and the person needs to be told which one it was.
     */
    private function respond(CharacterActionResult $result, Character $character): JsonResponse
    {
        $message = $result->message($character->name);

        if (! $result->succeeded()) {
            return response()->json([
                'message' => $message,
                'reason' => $result->value,
            ], 422);
        }

        return response()->json([
            'message' => $message,
            'data' => CharacterResource::make($character->refresh()),
        ]);
    }
}
