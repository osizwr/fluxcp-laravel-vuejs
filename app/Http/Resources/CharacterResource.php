<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Character;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A character as the API exposes it.
 *
 * @mixin Character
 */
final class CharacterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->char_id,
            'account_id' => $this->when(
                $request->user()?->can('SeeAccountID') ?? false,
                fn (): int => $this->account_id,
            ),
            'name' => $this->name,
            'slot' => $this->char_num,
            'job_id' => $this->jobId(),
            'base_level' => $this->base_level,
            'job_level' => $this->job_level,
            'zeny' => $this->zeny,
            'online' => $this->online,

            /*
             * A character's current map is withheld unless the viewer holds
             * ViewOnlinePosition. The legacy panel gated this because a public
             * who-is-online page showing locations lets players track each
             * other, and during a siege lets them scout castles.
             */
            'map' => $this->when(
                $request->user()?->can('ViewOnlinePosition') ?? false,
                fn (): string => $this->last_map,
            ),

            'guild' => $this->when(
                $this->isInGuild(),
                fn (): array => [
                    'id' => $this->guild_id,
                    'name' => $this->whenLoaded('guild', fn () => $this->guild?->name),
                ],
            ),

            'is_married' => $this->isMarried(),
            'pending_deletion' => $this->isPendingDeletion(),
            'deletion_final_at' => $this->deletionBecomesFinalAt()?->toIso8601String(),
            'last_login_at' => $this->last_login?->toIso8601String(),
        ];
    }
}
