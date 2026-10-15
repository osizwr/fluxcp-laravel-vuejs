<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Rules\SafeLinkUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The download page, as the dashboard submits it.
 *
 * The whole section is sent and stored in one go rather than patched field by
 * field. These are a handful of cards an operator edits as a unit -- adding a
 * mirror means reordering the list it is in -- and a partial update would need
 * a stable identity for a package that the configuration format does not have.
 *
 * Every length limit here is a limit on what the page can be made to render,
 * not a guess at what an operator might want to type. They are generous.
 */
final class UpdateDownloadSettingsRequest extends FormRequest
{
    /**
     * The platforms the download page knows how to draw an icon and a label
     * for. Anything else is stored as "no platform", which renders the card
     * without either rather than guessing.
     *
     * @var list<string>
     */
    public const PLATFORMS = ['windows', 'macos', 'linux', 'android', 'ios'];

    public function authorize(): bool
    {
        // The permission middleware holds this route at Administrator.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'notice' => ['present', 'nullable', 'string', 'max:500'],

            /*
             * `present` rather than `required`: a server that publishes no
             * client at all is a real state -- one being stood up -- and the
             * page handles it. Sending the key is still required, so an empty
             * list has to be deliberate rather than the result of a field the
             * client forgot to include.
             */
            'clients' => ['present', 'array', 'max:12'],

            'clients.*.name' => ['required', 'string', 'max:100'],
            'clients.*.platform' => ['nullable', Rule::in(self::PLATFORMS)],
            'clients.*.badge' => ['nullable', 'string', 'max:24'],
            'clients.*.version' => ['nullable', 'string', 'max:50'],
            'clients.*.size' => ['nullable', 'string', 'max:50'],
            'clients.*.updated' => ['nullable', 'string', 'max:50'],
            'clients.*.description' => ['nullable', 'string', 'max:1000'],

            'clients.*.mirrors' => ['present', 'array', 'max:10'],
            'clients.*.mirrors.*.label' => ['required', 'string', 'max:60'],
            'clients.*.mirrors.*.url' => ['required', 'string', 'max:2048', new SafeLinkUrl],

            'clients.*.notes' => ['present', 'array', 'max:10'],
            'clients.*.notes.*' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'clients.*.name' => 'package name',
            'clients.*.mirrors.*.label' => 'mirror name',
            'clients.*.mirrors.*.url' => 'mirror link',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'clients.*.name.required' => 'Give every package a name.',
            'clients.*.mirrors.*.label.required' => 'Give every mirror a name.',
            'clients.*.mirrors.*.url.required' => 'Give every mirror a link, or remove it.',
        ];
    }

    /**
     * The validated payload, normalised to the shape config/game.php uses.
     *
     * Done here rather than in the controller because it is part of reading
     * the request: by the time the controller has it, "what the operator sent"
     * and "what gets stored" should already be the same thing.
     *
     * Blank strings become null, which is the distinction the download page
     * draws on -- a null version is a line it leaves out, an empty string is
     * a gap where a line should be.
     *
     * @return array{notice: string|null, clients: list<array<string, mixed>>}
     */
    public function settings(): array
    {
        /** @var array{notice?: string|null, clients?: array<int, array<string, mixed>>} $data */
        $data = $this->validated();

        $clients = [];

        foreach (array_values($data['clients'] ?? []) as $client) {
            $mirrors = [];

            foreach (array_values((array) ($client['mirrors'] ?? [])) as $mirror) {
                $mirrors[] = [
                    'label' => trim((string) $mirror['label']),
                    'url' => trim((string) $mirror['url']),
                ];
            }

            $notes = [];

            foreach ((array) ($client['notes'] ?? []) as $note) {
                $trimmed = trim((string) $note);

                if ($trimmed !== '') {
                    $notes[] = $trimmed;
                }
            }

            $clients[] = [
                'name' => trim((string) $client['name']),
                'platform' => $this->nullIfBlank($client['platform'] ?? null),
                'badge' => $this->nullIfBlank($client['badge'] ?? null),
                'version' => $this->nullIfBlank($client['version'] ?? null),
                'size' => $this->nullIfBlank($client['size'] ?? null),
                'updated' => $this->nullIfBlank($client['updated'] ?? null),
                'description' => $this->nullIfBlank($client['description'] ?? null) ?? '',
                'mirrors' => $mirrors,
                'notes' => $notes,
            ];
        }

        return [
            'notice' => $this->nullIfBlank($data['notice'] ?? null),
            'clients' => $clients,
        ];
    }

    private function nullIfBlank(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
