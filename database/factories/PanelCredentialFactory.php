<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PanelCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PanelCredential>
 */
final class PanelCredentialFactory extends Factory
{
    protected $model = PanelCredential::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'server_group' => 'main',
            'account_id' => $this->faker->unique()->numberBetween(2000000, 2999999),
            // Hashed by the model's cast on assignment.
            'password_hash' => 'password',
            'remember_token' => null,
        ];
    }

    public function forAccount(int $accountId, string $serverGroup = 'main'): self
    {
        return $this->state(fn (): array => [
            'account_id' => $accountId,
            'server_group' => $serverGroup,
        ]);
    }

    public function withPassword(string $password): self
    {
        return $this->state(fn (): array => ['password_hash' => $password]);
    }
}
