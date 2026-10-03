<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Account;
use App\Support\Authorization\PermissionRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An account as the API exposes it.
 *
 * @mixin Account
 */
final class AccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /*
         * Nothing from login.user_pass, pincode or web_auth_token appears
         * here. The model hides them, and this resource lists fields
         * explicitly rather than spreading the model, so a future column added
         * by rAthena cannot leak through by default.
         */
        return [
            'id' => $this->account_id,
            'username' => $this->userid,
            'email' => $this->email,
            'gender' => $this->sex->value,
            'group' => [
                'id' => $this->group_id,
                'name' => $this->groupName(),
                'level' => $this->accountLevel()->value,
                'label' => $this->accountLevel()->label(),
                'is_staff' => $this->isStaff(),
            ],
            'state' => [
                'code' => $this->state,
                'label' => $this->state()?->label(),
                'permanently_banned' => $this->isPermanentlyBanned(),
                'temporarily_banned' => $this->isTemporarilyBanned(),
                'ban_expires_at' => $this->temporaryBanExpiresAt()?->toIso8601String(),
                'expired' => $this->hasExpired(),
            ],
            'is_vip' => $this->isVip(),
            'character_slots' => $this->character_slots,
            'login_count' => $this->logincount,
            'last_login_at' => $this->lastlogin?->toIso8601String(),
            'birthdate' => $this->birthdate?->toDateString(),

            /*
             * Always an integer, never null.
             *
             * `whenLoaded` looks like it handles this and does not: when a
             * relation is loaded but resolves to null it returns null, not the
             * default — the default only covers the relation not being loaded
             * at all. An account with no `cp_credits` row is exactly that
             * case, and it is the common one, because the row is created on
             * first donation rather than with the account.
             *
             * The client declares this field as `number` and calls
             * `.toLocaleString()` on it, so a null here is a TypeError on the
             * account page rather than a missing figure.
             */
            'credits' => $this->relationLoaded('credit')
                ? (int) ($this->credit?->balance ?? 0)
                : 0,

            'characters' => CharacterResource::collection($this->whenLoaded('characters')),
        ];
    }

    /**
     * Additional data sent with the authenticated account.
     *
     * The permission set is included so the client can hide what the viewer
     * cannot do. It is a convenience for the interface only: every one of
     * these is enforced again on the server, and hiding a control is never
     * what stops an action.
     *
     * @return array<string, mixed>
     */
    public function with(Request $request): array
    {
        if (! $request->boolean('with_permissions')) {
            return [];
        }

        return [
            'meta' => [
                'permissions' => app(PermissionRegistry::class)
                    ->abilitiesFor($this->accountLevel()),
            ],
        ];
    }
}
