<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Character;
use App\Models\Guild;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds rows in rAthena's `guild` table, for tests only.
 *
 * @extends Factory<Guild>
 */
final class GuildFactory extends Factory
{
    protected $model = Guild::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->company(),
            'char_id' => 0,
            'master' => $this->faker->firstName(),
            'guild_lv' => 1,
            'connect_member' => 1,
            'max_member' => 16,
            'average_lv' => 1,
            'exp' => 0,
            'next_exp' => 0,
            'skill_point' => 0,
            'mes1' => '',
            'mes2' => '',
            'emblem_len' => 0,
            'emblem_id' => 0,
            'emblem_data' => null,
        ];
    }

    public function named(string $name): self
    {
        return $this->state(fn (): array => ['name' => $name]);
    }

    /**
     * rAthena denormalises the master's name onto the guild row as well as
     * holding their char_id, so both are set together.
     */
    public function ledBy(Character $leader): self
    {
        return $this->state(fn (): array => [
            'char_id' => $leader->char_id,
            'master' => $leader->name,
        ]);
    }

    public function withEmblem(string $bitmap = 'BM-placeholder'): self
    {
        return $this->state(fn (): array => [
            'emblem_data' => $bitmap,
            'emblem_len' => strlen($bitmap),
            'emblem_id' => $this->faker->numberBetween(1, 99999),
        ]);
    }
}
