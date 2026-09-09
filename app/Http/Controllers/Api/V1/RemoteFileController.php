<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class RemoteFileController extends Controller
{
    /**
     * Display a document or journal evidence file stored
     * inside the selected school's uploads directory.
     */
    public function upload(
        Request $request,
        string $filename,
    ): StreamedResponse {
        return $this->streamRemoteFile(
            request: $request,
            storageType: 'uploads',
            filename: $filename,
        );
    }

    /**
     * Display an activity file, activity evidence, or officer
     * signature stored inside the person_task directory.
     */
    public function personTask(
        Request $request,
        string $filename,
    ): StreamedResponse {
        return $this->streamRemoteFile(
            request: $request,
            storageType: 'person_task',
            filename: $filename,
        );
    }

    /**
     * Display a student's electronic signature stored
     * inside the selected school's images directory.
     */
    public function signature(
        Request $request,
        string $filename,
    ): StreamedResponse {
        return $this->streamRemoteFile(
            request: $request,
            storageType: 'esig',
            filename: $filename,
        );
    }

    /**
     * Resolve the tenant FTP disk and stream the requested file.
     */
    private function streamRemoteFile(
        Request $request,
        string $storageType,
        string $filename,
    ): StreamedResponse {
        $safeFilename = $this->validateFilename(
            $filename,
        );

        $diskName = $this->resolveDiskName(
            request: $request,
            storageType: $storageType,
        );

        $disk = Storage::disk($diskName);

        try {
            if (! $disk->exists($safeFilename)) {
                abort(
                    Response::HTTP_NOT_FOUND,
                    'The requested file was not found.',
                );
            }

            $stream = $disk->readStream(
                $safeFilename,
            );

            if ($stream === false) {
                abort(
                    Response::HTTP_BAD_GATEWAY,
                    'The requested file could not be read from remote storage.',
                );
            }

            $mimeType = $this->resolveMimeType(
                disk: $disk,
                filename: $safeFilename,
            );

            $disposition = HeaderUtils::makeDisposition(
                disposition: HeaderUtils::DISPOSITION_INLINE,
                filename: $safeFilename,
                filenameFallback: $this->fallbackFilename(
                    $safeFilename,
                ),
            );

            return response()->stream(
                function () use ($stream): void {
                    try {
                        fpassthru($stream);
                    } finally {
                        if (is_resource($stream)) {
                            fclose($stream);
                        }
                    }
                },
                Response::HTTP_OK,
                [
                    'Content-Type' =>
                        $mimeType,

                    'Content-Disposition' =>
                        $disposition,

                    'Cache-Control' =>
                        'private, max-age=3600',

                    'Pragma' =>
                        'private',

                    'X-Content-Type-Options' =>
                        'nosniff',
                ],
            );
        } catch (Throwable $exception) {
            /*
             * Preserve Laravel HTTP exceptions such as 404.
             */
            if (
                method_exists($exception, 'getStatusCode')
            ) {
                throw $exception;
            }

            Log::error(
                'Unable to read a remote FTP file.',
                [
                    'school_code' =>
                        $request
                            ->session()
                            ->get('school_code'),

                    'disk' =>
                        $diskName,

                    'filename' =>
                        $safeFilename,

                    'exception' =>
                        $exception->getMessage(),
                ],
            );

            abort(
                Response::HTTP_BAD_GATEWAY,
                'The remote file storage is currently unavailable.',
            );
        }
    }

    /**
     * Resolve the FTP disk from the school selected during login.
     */
    private function resolveDiskName(
        Request $request,
        string $storageType,
    ): string {
        $schoolCode = strtolower(
            trim(
                (string) $request
                    ->session()
                    ->get('school_code', ''),
            ),
        );

        abort_if(
            $schoolCode === '',
            Response::HTTP_FORBIDDEN,
            'No school has been selected.',
        );

        abort_unless(
            preg_match(
                '/\A[a-z0-9_-]+\z/',
                $schoolCode,
            ) === 1,
            Response::HTTP_FORBIDDEN,
            'The selected school code is invalid.',
        );

        $allowedStorageTypes = [
            'uploads',
            'person_task',
            'esig',
        ];

        abort_unless(
            in_array(
                $storageType,
                $allowedStorageTypes,
                true,
            ),
            Response::HTTP_BAD_REQUEST,
            'The requested storage type is invalid.',
        );

        $diskName = sprintf(
            'admapro_%s_%s',
            $schoolCode,
            $storageType,
        );

        $diskConfiguration = config(
            "filesystems.disks.{$diskName}",
        );

        abort_unless(
            is_array($diskConfiguration),
            Response::HTTP_INTERNAL_SERVER_ERROR,
            'The selected school file storage is not configured.',
        );

        $requiredConfiguration = [
            'driver',
            'host',
            'username',
            'password',
            'root',
        ];

        foreach ($requiredConfiguration as $key) {
            $value = $diskConfiguration[$key] ?? null;

            abort_if(
                ! is_string($value) ||
                trim($value) === '',
                Response::HTTP_INTERNAL_SERVER_ERROR,
                sprintf(
                    'The selected school file storage is missing its %s configuration.',
                    $key,
                ),
            );
        }

        return $diskName;
    }

    /**
     * Validate and normalize a requested filename.
     */
    private function validateFilename(
        string $filename,
    ): string {
        $decodedFilename = rawurldecode(
            trim($filename),
        );

        abort_if(
            $decodedFilename === '',
            Response::HTTP_BAD_REQUEST,
            'The requested filename is empty.',
        );

        /*
         * Convert Windows separators before checking basename.
         */
        $normalizedFilename = str_replace(
            '\\',
            '/',
            $decodedFilename,
        );

        $safeFilename = basename(
            $normalizedFilename,
        );

        /*
         * Reject path traversal and nested directories.
         */
        abort_unless(
            hash_equals(
                $normalizedFilename,
                $safeFilename,
            ),
            Response::HTTP_BAD_REQUEST,
            'The requested filename is invalid.',
        );

        abort_if(
            in_array(
                $safeFilename,
                [
                    '.',
                    '..',
                ],
                true,
            ),
            Response::HTTP_BAD_REQUEST,
            'The requested filename is invalid.',
        );

        abort_if(
            str_contains(
                $safeFilename,
                "\0",
            ),
            Response::HTTP_BAD_REQUEST,
            'The requested filename is invalid.',
        );

        return $safeFilename;
    }

    /**
     * Resolve the MIME type without failing the file request when
     * the FTP server cannot provide MIME information.
     */
    private function resolveMimeType(
        Filesystem $disk,
        string $filename,
    ): string {
        try {
            $mimeType = $disk->mimeType(
                $filename,
            );

            if (
                is_string($mimeType) &&
                $mimeType !== ''
            ) {
                return $mimeType;
            }
        } catch (Throwable) {
            // Fall back to the filename extension.
        }

        return match (
            strtolower(
                pathinfo(
                    $filename,
                    PATHINFO_EXTENSION,
                ),
            )
        ) {
            'pdf' =>
                'application/pdf',

            'jpg', 'jpeg' =>
                'image/jpeg',

            'png' =>
                'image/png',

            'gif' =>
                'image/gif',

            'webp' =>
                'image/webp',

            'doc' =>
                'application/msword',

            'docx' =>
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',

            'xls' =>
                'application/vnd.ms-excel',

            'xlsx' =>
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',

            'txt' =>
                'text/plain; charset=UTF-8',

            default =>
                'application/octet-stream',
        };
    }

    /**
     * Generate an ASCII fallback for Content-Disposition.
     */
    private function fallbackFilename(
        string $filename,
    ): string {
        $extension = pathinfo(
            $filename,
            PATHINFO_EXTENSION,
        );

        $basename = pathinfo(
            $filename,
            PATHINFO_FILENAME,
        );

        $fallback = preg_replace(
            '/[^A-Za-z0-9._-]+/',
            '-',
            $basename,
        );

        $fallback = trim(
            (string) $fallback,
            '-_.',
        );

        if ($fallback === '') {
            $fallback = 'file';
        }

        if ($extension !== '') {
            return $fallback.'.'.strtolower(
                preg_replace(
                    '/[^A-Za-z0-9]+/',
                    '',
                    $extension,
                ) ?: 'bin',
            );
        }

        return $fallback;
    }
}