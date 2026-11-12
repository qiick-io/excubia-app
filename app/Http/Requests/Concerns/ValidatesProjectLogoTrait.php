<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Services\SvgSanitizerService;
use Closure;
use Illuminate\Http\UploadedFile;

/**
 * The optional project logo of the project form (#287): PNG, JPEG, WebP or SVG up to 256 KB,
 * the type read from the content. An SVG must also parse as an SVG document without a DOCTYPE,
 * so SaveProjectLogoAction can sanitise it.
 */
trait ValidatesProjectLogoTrait
{
    public const int LOGO_MAX_KILOBYTES = 256;

    /**
     * @return array<string, list<mixed>>
     */
    protected function projectLogoRules(): array
    {
        return [
            'logo' => [
                'nullable',
                'file',
                'mimes:png,jpg,jpeg,webp,svg',
                'max:'.self::LOGO_MAX_KILOBYTES,
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value instanceof UploadedFile
                        && $value->extension() === 'svg'
                        && app(SvgSanitizerService::class)->sanitize((string) $value->get()) === null) {
                        $fail(__('The logo is not a valid SVG image.'));
                    }
                },
            ],
        ];
    }

    /** The uploaded logo, when the form sent one. */
    public function logoUpload(): ?UploadedFile
    {
        $upload = $this->file('logo');

        return $upload instanceof UploadedFile ? $upload : null;
    }
}
