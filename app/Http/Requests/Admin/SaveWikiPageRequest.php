<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A wiki page as the dashboard submits it.
 *
 * The slug rules are the strict halves of what the filesystem allows, not the
 * loose ones. WikiLibrary derives a slug from whatever a file happens to be
 * called -- `Creating an Account.md` becomes `creating-an-account` -- because
 * it has to cope with names it did not choose. A page created here is a name
 * somebody is choosing right now, so it is required to be the thing the URL
 * will actually be, rather than quietly becoming something else after they
 * press save.
 *
 * The same pattern as the public route's own constraint, so a page this
 * accepts is a page that route can serve. A slug these disagreed about would
 * be a page that saves and then 404s.
 */
final class SaveWikiPageRequest extends FormRequest
{
    /** Lower-case, digits and hyphens, not starting with a hyphen. */
    public const SLUG = 'regex:/^[a-z0-9][a-z0-9\-]*$/';

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
             * Only on create. An edit addresses its page by the URL, and
             * moving a page is deliberately not an edit: whatever linked to
             * the old path would break silently, so it is a delete and a
             * create, which at least looks like what it is.
             */
            'category' => ['required', 'string', 'max:96', self::SLUG],
            'slug' => ['required', 'string', 'max:96', self::SLUG],

            'title' => ['required', 'string', 'max:191'],
            'summary' => ['nullable', 'string', 'max:500'],

            /*
             * Null sorts the page after every page that declares a number,
             * which is the same rule the front matter on disk follows.
             */
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],

            /*
             * Markdown, stored exactly as typed and rendered on the way out
             * through ContentRenderer -- which strips raw HTML. Storing the
             * rendered form instead is how the legacy news editor became a
             * stored-XSS vector. See docs/MIGRATION_DECISIONS.md (D20).
             */
            'body' => ['required', 'string', 'max:262144'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'category.regex' => 'The section must be lower-case letters, numbers and hyphens.',
            'slug.regex' => 'The page address must be lower-case letters, numbers and hyphens.',
        ];
    }
}
