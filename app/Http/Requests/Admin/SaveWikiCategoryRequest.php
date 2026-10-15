<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Services\Content\WikiImages;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A wiki section as the dashboard submits it.
 *
 * The slug rule is the strict half of what the filesystem allows, for the
 * reason SaveWikiPageRequest gives at length: WikiLibrary derives a slug from
 * whatever a directory happens to be called because it has to cope with names
 * it did not choose, whereas a section created here is a name somebody is
 * choosing right now, and it should be the thing the URL will actually be
 * rather than quietly becoming something else after they press save.
 */
final class SaveWikiCategoryRequest extends FormRequest
{
    /**
     * The icons a section may carry.
     *
     * The same list, in the same order, as the one WikiIcon draws. Kept in two
     * places because they are two languages, and paired by this comment: a
     * name that is in one and not the other is an icon that saves and then
     * renders as the fallback book, which looks like the save went wrong.
     *
     * @var list<string>
     */
    public const ICONS = [
        'book',
        'play',
        'globe',
        'help',
        'sword',
        'shield',
        'coins',
        'key',
        'calendar',
        'flame',
        'map',
        'users',
    ];

    public function authorize(): bool
    {
        // The permission middleware holds these routes at Administrator.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * Only on create, like a page's address. Renaming a section would
             * move every page in it, and every link to every one of those
             * pages would break silently -- so it is not an edit.
             */
            'slug' => ['required', 'string', 'max:96', SaveWikiPageRequest::SLUG],

            'title' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string', 'max:500'],

            /*
             * One of the drawn set or nothing at all. Deliberately not a path
             * or a URL: see WikiIcon for why a section's mark is a name rather
             * than an image somebody can point anywhere.
             */
            'icon' => ['nullable', 'string', Rule::in(self::ICONS)],

            /*
             * The section's art, and only ever one this panel stored itself.
             *
             * Deliberately not a free URL field. A tile pointed at somebody
             * else's server hands that server the IP address of everyone who
             * loads /wiki, and takes a ninth of the index down with it when
             * it goes. The uploader is the only way in.
             */
            'image' => [
                'nullable',
                'string',
                'max:191',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && ! app(WikiImages::class)->isSectionImage($value)) {
                        $fail('That image was not uploaded here.');
                    }
                },
            ],

            /*
             * Null sorts the section after every section that declares a
             * number, which is the same rule `_category.md` follows.
             */
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'slug.regex' => 'The section address must be lower-case letters, numbers and hyphens.',
            'icon.in' => 'That is not one of the available icons.',
        ];
    }
}
