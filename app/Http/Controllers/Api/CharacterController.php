<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\CharacterResource;
use App\Models\Character;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Character listings.
 */
final class CharacterController
{
    /**
     * Characters currently in the world.
     *
     * Public, as in the legacy panel, but each character's map is withheld
     * unless the viewer holds ViewOnlinePosition -- the resource decides that,
     * so the rule is applied once rather than per caller.
     *
     * Players who have set the Hidden preference are excluded unless the viewer
     * may ignore it, reproducing the legacy IgnoreHiddenPref behaviour.
     */
    public function online(Request $request): AnonymousResourceCollection
    {
        $perPage = min(
            $request->integer('per_page', (int) config('panel.pagination.per_page', 20)),
            (int) config('panel.pagination.max_per_page', 100),
        );

        $query = Character::query()
            ->online()
            ->notDeleted()
            ->with('guild')
            ->orderBy('name');

        if ($request->user()?->can('IgnoreHiddenPref') !== true) {
            $query->whereNotExists(function ($sub): void {
                $sub->selectRaw('1')
                    ->from('cp_charprefs')
                    ->whereColumn('cp_charprefs.char_id', 'char.char_id')
                    ->where('cp_charprefs.name', 'Hidden')
                    ->where('cp_charprefs.value', '1');
            });
        }

        if (($name = $request->string('name')->trim()->value()) !== '') {
            // Escaped so a visitor cannot turn the search into a wildcard scan.
            $query->where('name', 'like', '%'.addcslashes($name, '%_\\').'%');
        }

        return CharacterResource::collection($query->paginate($perPage)->withQueryString());
    }

    /**
     * The signed-in account's own characters.
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        $account = $request->user();

        abort_unless($account !== null, 401);

        $characters = Character::query()
            ->where('account_id', $account->getAuthIdentifier())
            ->notDeleted()
            ->with('guild')
            ->orderBy('char_num')
            ->get();

        return CharacterResource::collection($characters);
    }
}
