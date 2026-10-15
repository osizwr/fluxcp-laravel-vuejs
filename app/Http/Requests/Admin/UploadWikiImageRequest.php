<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An image on its way into a wiki page.
 *
 * Four separate questions are asked about the same file, because each one
 * catches something the others do not:
 *
 *   image       is it something the server can actually decode as a picture
 *   mimes       do the extension and the detected type agree, and are they
 *               both on the list -- `shell.php.png` fails here
 *   max         is the file small enough to be worth serving
 *   dimensions  is the decoded size sane, which the byte count cannot say:
 *               a 10KB PNG can declare itself 50,000 pixels square
 *
 * SVG is not on the list, and that is the one exclusion worth naming. It is an
 * image to a browser and a document to a sanitiser -- it can carry script --
 * and the whole content pipeline here is built on never serving markup an
 * operator authored. See ContentRenderer and config/wiki.php.
 *
 * The file's own name is never used for anything after this. See
 * WikiImageController for why the stored name comes from the contents.
 */
final class UploadWikiImageRequest extends FormRequest
{
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
            'image' => [
                'required',
                'file',
                /*
                 * Laravel's own `image` rule has allowed SVG in some versions
                 * and not in others, so it is not what excludes it: `mimes`
                 * is the list, and SVG is not on it.
                 */
                'image',
                'mimes:'.implode(',', $this->extensions()),
                'max:'.max(1, (int) config('wiki.uploads.max_kilobytes', 4096)),
                'dimensions:max_width='.max(1, (int) config('wiki.uploads.max_width', 6000))
                    .',max_height='.max(1, (int) config('wiki.uploads.max_height', 6000)),
            ],

        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'image.image' => 'That file is not an image.',
            'image.mimes' => 'Images must be one of: '.implode(', ', $this->extensions()).'.',
            'image.max' => 'That image is larger than this panel accepts.',
            'image.dimensions' => 'That image is larger in pixels than this panel accepts.',
        ];
    }

    /**
     * @return list<string>
     */
    private function extensions(): array
    {
        /** @var list<string> $extensions */
        $extensions = (array) config('wiki.uploads.extensions', ['png', 'jpg']);

        return array_values(array_filter($extensions, 'is_string'));
    }
}
