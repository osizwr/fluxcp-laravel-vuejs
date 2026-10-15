<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A section's art on its way in.
 *
 * The same four questions UploadWikiImageRequest asks, with one of them made
 * strict: the size is exact rather than a maximum.
 *
 * That is the unusual rule and the deliberate one. The tiles on /wiki are a
 * grid of equal squares, and an image that has to be made square to fit is a
 * decision about somebody's artwork taken by a program that cannot see it --
 * squash it and the art is wrong, crop it and the subject may be the part that
 * was cut. Asking for the square that is going to be drawn is the only option
 * that does not quietly ruin somebody's picture.
 *
 * What is not strict is the format. Anything the server can decode is accepted
 * and converted to WebP on the way to disk; see WikiImages.
 */
final class UploadWikiSectionImageRequest extends FormRequest
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
        $width = max(1, (int) config('wiki.uploads.section_image.width', 1080));
        $height = max(1, (int) config('wiki.uploads.section_image.height', 1080));

        return [
            'image' => [
                'required',
                'file',
                'image',
                'mimes:'.implode(',', $this->extensions()),
                'max:'.max(1, (int) config('wiki.uploads.section_image.max_kilobytes', 8192)),
                'dimensions:width='.$width.',height='.$height,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $width = (int) config('wiki.uploads.section_image.width', 1080);
        $height = (int) config('wiki.uploads.section_image.height', 1080);

        return [
            'image.image' => 'That file is not an image.',
            'image.mimes' => 'Images must be one of: '.implode(', ', $this->extensions()).'.',
            'image.max' => 'That image is larger than this panel accepts.',
            // Said as the thing to do rather than as what went wrong, because
            // the fix is to export it again at one specific size.
            'image.dimensions' => "A section image must be exactly {$width} by {$height} pixels.",
        ];
    }

    /**
     * @return list<string>
     */
    private function extensions(): array
    {
        /** @var list<string> $extensions */
        $extensions = (array) config('wiki.uploads.section_image.extensions', ['png', 'jpg']);

        return array_values(array_filter($extensions, 'is_string'));
    }
}
