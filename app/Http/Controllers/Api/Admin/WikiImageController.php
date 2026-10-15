<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Requests\Admin\UploadWikiImageRequest;
use App\Http\Requests\Admin\UploadWikiSectionImageRequest;
use App\Services\Content\ContentRenderer;
use App\Services\Content\WikiImages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The two things the page editor needs that are not a page.
 *
 * An image an operator drops into the editor has to go somewhere before it can
 * be written into the Markdown as a URL, and the editor's preview has to be
 * the real thing rather than an approximation of it. Both are here because
 * both are the editor's own plumbing rather than anything about a page.
 *
 * ---------------------------------------------------------------------------
 * Why the stored name comes from the file's contents
 * ---------------------------------------------------------------------------
 *
 * The obvious implementation keeps the name the browser sent. That name is
 * attacker-controlled input being used to build a path, which is the one thing
 * WikiLibrary is written at length to never do -- and it brings a second
 * problem with it, which is that two people uploading `screenshot.png` either
 * overwrite each other or start a numbering scheme.
 *
 * Hashing the contents answers both and a third: the same image pasted into
 * four pages is one file on disk, and re-uploading it after an edit is a
 * no-op rather than a duplicate.
 */
final class WikiImageController
{
    public function __construct(
        private readonly ContentRenderer $renderer,
        private readonly WikiImages $images,
    ) {}

    /**
     * Take an image and hand back the URL to write into the page.
     *
     * The response is deliberately small: the editor needs somewhere to point
     * a `![alt](url)` and nothing else. Nothing is recorded in the database --
     * an image is referenced by the Markdown that mentions it, and a table of
     * uploads would only be a second place for that to be true or false.
     */
    public function store(UploadWikiImageRequest $request): JsonResponse
    {
        $file = $request->file('image');

        return response()->json([
            'data' => [
                'url' => $this->images->store($file),
                /*
                 * What the editor writes between the brackets: the name the
                 * operator knew the file by, which is a better guess at what
                 * the image is of than a hash is, and is sitting in the
                 * Markdown to be corrected. It only ever becomes alt text,
                 * never a path.
                 */
                'alt' => $this->altFor($file->getClientOriginalName()),
            ],
        ], 201);
    }

    /**
     * The body as readers will actually see it.
     *
     * Rendered here rather than in the browser, which is the whole point: a
     * Markdown library in the editor would be a second opinion about what the
     * page says, and the first time the two disagreed would be the first time
     * somebody published something they had not seen. This is the same
     * ContentRenderer the public page goes through, so the preview strips the
     * HTML the published page strips and refuses the links it refuses.
     */
    public function preview(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:262144'],
        ]);

        return response()->json([
            'data' => ['html' => $this->renderer->toHtml($validated['body'] ?? '')],
        ]);
    }

    /**
     * A section's art: one exact square, stored as WebP.
     *
     * A separate action from store() above because it is a separate job with
     * separate rules -- see UploadWikiSectionImageRequest for why the size is
     * exact, and WikiImages for why the format is not kept.
     */
    public function storeSectionImage(UploadWikiSectionImageRequest $request): JsonResponse
    {
        try {
            $url = $this->images->storeSectionImage($request->file('image'));
        } catch (RuntimeException $e) {
            /*
             * The request has already established that this decodes as an
             * image, so getting here means GD on this server was not built
             * with the format. Reported as a validation failure because that
             * is what it is from the operator's side -- this file, not this
             * server -- and it is the field the message belongs beside.
             */
            return response()->json([
                'message' => $e->getMessage(),
                'errors' => ['image' => [$e->getMessage()]],
            ], 422);
        }

        return response()->json(['data' => ['url' => $url]], 201);
    }

    /**
     * A first guess at alt text, from the name the file arrived under.
     */
    private function altFor(?string $filename): string
    {
        $stem = pathinfo((string) $filename, PATHINFO_FILENAME);

        /*
         * Brackets and parentheses would end the Markdown early and leave the
         * URL sitting in the page as text. Stripped rather than escaped: this
         * is a guess at a caption, and an operator who wants punctuation in
         * one can type it.
         */
        $stem = trim(preg_replace('/[\[\]()\r\n]+/', ' ', $stem) ?? '');

        return $stem === '' ? 'Image' : mb_substr($stem, 0, 191);
    }
}
