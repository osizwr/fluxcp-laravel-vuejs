<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Gender;
use App\Models\Account;
use App\Models\Character;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds rows in rAthena's `char` table, for tests only.
 *
 * @extends Factory<Character>
 */
final class CharacterFactory extends Factory
{
    protected $model = Character::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'char_num' => 0,
            'name' => $this->faker->unique()->firstName().$this->faker->unique()->numberBetween(100, 999),
            'class' => 0,
            'base_level' => 1,
            'job_level' => 1,
            'base_exp' => 0,
            'job_exp' => 0,
            'zeny' => 0,
            'guild_id' => 0,
            'party_id' => 0,
            'partner_id' => 0,
            'online' => 0,
            'delete_date' => 0,
            'sex' => Gender::Male,
            'last_map' => 'prontera',
        ];
    }

    public function forAccount(Account $account): self
    {
        return $this->state(fn (): array => ['account_id' => $account->account_id]);
    }

    public function named(string $name): self
    {
        return $this->state(fn (): array => ['name' => $name]);
    }

    public function online(): self
    {
        return $this->state(fn (): array => ['online' => 1]);
    }

    public function atLevel(int $baseLevel, int $jobLevel = 1): self
    {
        return $this->state(fn (): array => [
            'base_level' => $baseLevel,
            'job_level' => $jobLevel,
        ]);
    }

    public function withZeny(int $zeny): self
    {
        return $this->state(fn (): array => ['zeny' => $zeny]);
    }

    public function inGuild(int $guildId): self
    {
        return $this->state(fn (): array => ['guild_id' => $guildId]);
    }

    public function inSlot(int $slot): self
    {
        return $this->state(fn (): array => ['char_num' => $slot]);
    }

    /**
     * Queued for deletion: rAthena sets delete_date to the Unix time at which
     * the deletion becomes final rather than removing the row.
     */
    public function pendingDeletion(?int $finalisesAt = null): self
    {
        return $this->state(fn (): array => [
            'delete_date' => $finalisesAt ?? (time() + 86400),
        ]);
    }

    public function marriedTo(Character $partner): self
    {
        return $this->state(fn (): array => ['partner_id' => $partner->char_id]);
    }

    public function onMap(string $map): self
    {
        return $this->state(fn (): array => ['last_map' => $map]);
    }
}
