<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Project;
use App\Services\SvgSanitizerService;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Sets, replaces or removes a project's logo (#287). The file is stored on the public disk
 * under a random name (the name doubles as the cache-busting version of its URL); the file it
 * replaces is deleted. Raster images are re-encoded as a transparent square PNG, which also
 * drops metadata and anything appended to the image; SVGs are sanitised (SvgSanitizerService)
 * and kept as vectors, drawn square by the avatar. Without a logo the project shows its
 * initials avatar.
 */
final class SaveProjectLogoAction
{
    public const string DISK = 'public';

    public const string DIRECTORY = 'project-logos';

    /** Edge of the stored square in pixels: sharp at the largest avatar (40px) on 3x screens. */
    public const int SIZE = 128;

    public function __construct(private SvgSanitizerService $svgSanitizer) {}

    /**
     * Nothing changes without an upload and without `$shouldRemove`; an upload wins over a removal.
     *
     * @throws ValidationException when an SVG cannot be sanitised
     * @throws RuntimeException when the disk refuses the file
     */
    public function execute(Project $project, ?UploadedFile $upload, bool $shouldRemove = false): Project
    {
        if ($upload === null && ! $shouldRemove) {
            return $project;
        }

        $previousPath = $project->logo_path;
        $project->forceFill(['logo_path' => $upload === null ? null : $this->store($upload)])->save();

        if ($previousPath !== null && $previousPath !== $project->logo_path) {
            Storage::disk(self::DISK)->delete($previousPath);
        }

        return $project;
    }

    /** Deletes the logo file of a project that is going away. */
    public function deleteFile(Project $project): void
    {
        if ($project->logo_path !== null) {
            Storage::disk(self::DISK)->delete($project->logo_path);
        }
    }

    private function store(UploadedFile $upload): string
    {
        // The extension comes from the content (finfo), never from the client's file name.
        $extension = $upload->extension();
        $contents = $upload->get();

        if (! is_string($contents)) {
            throw new RuntimeException('The uploaded logo could not be read.');
        }

        if ($extension === 'svg') {
            $contents = $this->svgSanitizer->sanitize($contents)
                ?? throw ValidationException::withMessages(['logo' => __('The logo is not a valid SVG image.')]);
        } else {
            $squarePng = $this->squarePng($contents);

            if ($squarePng !== null) {
                [$contents, $extension] = [$squarePng, 'png'];
            }
        }

        $path = self::DIRECTORY.'/'.Str::random(32).'.'.$extension;

        if (! Storage::disk(self::DISK)->put($path, $contents)) {
            throw new RuntimeException('The project logo could not be stored.');
        }

        return $path;
    }

    /**
     * The image scaled to fit a SIZE × SIZE transparent square, centred, as PNG.
     *
     * ponytail: needs PHP's GD extension (in the Docker image). Without it, or for an image GD
     * cannot decode, the validated original is kept and the avatar's `object-cover` squares it
     * in the browser; it is still served as an image with `nosniff`.
     */
    private function squarePng(string $contents): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }

        $sourceImage = @imagecreatefromstring($contents);

        if (! $sourceImage instanceof GdImage) {
            return null;
        }

        $sourceWidth = imagesx($sourceImage);
        $sourceHeight = imagesy($sourceImage);
        $scale = self::SIZE / max($sourceWidth, $sourceHeight, 1);
        $targetWidth = max(1, (int) round($sourceWidth * $scale));
        $targetHeight = max(1, (int) round($sourceHeight * $scale));

        $squareImage = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($squareImage, false);
        imagesavealpha($squareImage, true);
        imagefill($squareImage, 0, 0, (int) imagecolorallocatealpha($squareImage, 0, 0, 0, 127));
        imagecopyresampled(
            $squareImage,
            $sourceImage,
            intdiv(self::SIZE - $targetWidth, 2),
            intdiv(self::SIZE - $targetHeight, 2),
            0,
            0,
            $targetWidth,
            $targetHeight,
            $sourceWidth,
            $sourceHeight,
        );

        ob_start();
        imagepng($squareImage);
        $png = ob_get_clean();

        return is_string($png) && $png !== '' ? $png : null;
    }
}
