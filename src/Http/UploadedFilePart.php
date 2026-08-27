<?php

declare(strict_types=1);

namespace Whity\Http;

use Whity\Sdk\Http\Request;

/**
 * Reads ONE named file part out of a multipart request, and turns every way
 * that can fail into a sentence with a status code.
 *
 * WHY THIS EXISTS AS A CLASS RATHER THAN AS A PRIVATE METHOD
 * -----------------------------------------------------------
 * There were already two hand-rolled copies of this logic in core —
 * {@see \Whity\Api\BrandingApiHandler} and {@see \Whity\Api\PluginsApiHandler} —
 * differing in which failures they distinguish and in what they say. The form
 * upload needed a third, on TWO surfaces (authenticated and public), and a third
 * and fourth copy is how the four come to disagree about what
 * `UPLOAD_ERR_INI_SIZE` means to a person.
 *
 * So the new callers share one reader. The two older ones are deliberately left
 * alone in this change — rewriting a working plugin-installer path to prove a
 * point about tidiness is a risk with no benefit to the feature in hand — but
 * this is the place they would move to, and the docblock says so rather than
 * leaving the next person to guess which of three readers is canonical.
 *
 * WHY `getUploadedFiles()` IS THE ONLY PATH
 * ------------------------------------------
 * FrankenPHP runs with `enable_post_data_reading` ON, so PHP has already parsed
 * the multipart body into `$_FILES` and DRAINED `php://input` before any of this
 * code runs. A raw-body fallback would therefore receive an empty string in
 * production, find no parts, and refuse every upload — while passing in tests,
 * where the body is present. {@see \Whity\Sdk\Http\Request::getUploadedFiles()}
 * handles both, which is why it is the seam rather than the parser.
 *
 * THE SIZE FAILURE IS NOT A GENERIC FAILURE
 * ------------------------------------------
 * A part PHP itself refused because the deployment's `upload_max_filesize` is
 * below the application's own ceiling arrives as `UPLOAD_ERR_INI_SIZE` with no
 * bytes attached. Reporting that as "the file could not be read" sends somebody
 * to check their network while their file is merely too big, and hides a
 * misconfigured deployment from the operator. It is reported as a size refusal,
 * and logged as an ini problem.
 */
final class UploadedFilePart
{
    /**
     * Static reader only — never instantiated.
     */
    private function __construct()
    {
    }

    /**
     * The named part's bytes, plus the two UNTRUSTED strings the client attached.
     *
     * The filename and media type are the caller's own words
     * ({@see \Whity\Sdk\Http\UploadedFile} says so in its docblock): neither may
     * be used to build a path or to decide a stored content type. They are
     * returned so a caller can show the first and cross-check the second against
     * what the bytes actually are.
     *
     * @return array{bytes: string, filename: ?string, media_type: ?string}
     *
     * @throws UploadedFilePartException With a client-safe message and the
     *         status it should be returned as.
     */
    public static function read(Request $request, string $field): array
    {
        try {
            $files = $request->getUploadedFiles();
        } catch (\Throwable $e) {
            // A malformed multipart body, or the parser's own cap. NEVER the
            // exception text — it names temp paths.
            throw new UploadedFilePartException(
                'The uploaded file could not be read.',
                400,
                'multipart parse failed: ' . $e->getMessage(),
                $e,
            );
        }

        $file = $files[$field] ?? null;
        if ($file === null) {
            throw new UploadedFilePartException(
                'A file (multipart field "' . $field . '") is required.',
                400,
            );
        }

        $error = $file->getError();
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new UploadedFilePartException(
                'That file is too large.',
                422,
                'upload refused by PHP itself (error ' . $error . ') — upload_max_filesize is '
                    . 'below this endpoint\'s ceiling; raise it so the application does the refusing',
            );
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new UploadedFilePartException(
                'The uploaded file could not be read.',
                400,
                'upload part carried error ' . $error,
            );
        }

        $path = $file->getStreamPath();
        $bytes = is_file($path) ? file_get_contents($path) : false;
        if ($bytes === false) {
            throw new UploadedFilePartException(
                'The uploaded file could not be read.',
                400,
                'could not read the spilled temp file for part "' . $field . '"',
            );
        }

        return [
            'bytes'      => $bytes,
            'filename'   => $file->getClientFilename(),
            'media_type' => $file->getClientMediaType(),
        ];
    }
}
