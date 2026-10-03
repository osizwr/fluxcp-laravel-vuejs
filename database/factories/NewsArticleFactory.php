<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\NewsArticle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NewsArticle>
 */
final class NewsArticleFactory extends Factory
{
    protected $model = NewsArticle::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => rtrim($this->faker->sentence(5), '.'),
            // The legacy editor stored HTML, so the fixture does too.
            'body' => '<p>'.$this->faker->paragraph().'</p>',
            'link' => '',
            'author' => $this->faker->userName(),
            'created' => $this->faker->dateTimeBetween('-1 year'),
            'modified' => null,
        ];
    }

    public function titled(string $title): self
    {
        return $this->state(fn (): array => ['title' => $title]);
    }

    public function withBody(string $body): self
    {
        return $this->state(fn (): array => ['body' => $body]);
    }

    public function publishedAt(string $when): self
    {
        return $this->state(fn (): array => ['created' => $when]);
    }

    public function linkingTo(string $url): self
    {
        return $this->state(fn (): array => ['link' => $url]);
    }
}
