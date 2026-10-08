<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ExternalAssessmentAccessService
{
    public const TYPE_THEORETICAL = 'theoretical';
    public const TYPE_PRACTICAL = 'practical';

    public function issueToken(
        string $type,
        string $schoolCode,
        string $assessmentId,
        CarbonImmutable|string|null $expiresAt = null,
    ): string {
        $type = $this->normalizeType($type);
        $schoolCode = strtoupper(trim($schoolCode));
        $assessmentId = trim($assessmentId);

        if ($schoolCode === '') {
            throw new RuntimeException('The school code is required.');
        }

        if (! Str::isUuid($assessmentId)) {
            throw new RuntimeException('The assessment ID is invalid.');
        }

        $school = config("schools.schools.{$schoolCode}");

        if (! is_array($school)) {
            throw new RuntimeException('The selected school is not configured.');
        }

        $expiry = $this->normalizeExpiry($expiresAt);

        $payload = [
            'version' => 1,
            'type' => $type,
            'school' => $schoolCode,
            'assessment_id' => $assessmentId,
            'expires_at' => $expiry?->toIso8601String(),
        ];

        try {
            $encrypted = Crypt::encryptString(
                json_encode($payload, JSON_THROW_ON_ERROR),
            );
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Unable to create the external assessment access token.',
                0,
                $exception,
            );
        }

        return rtrim(
            strtr(
                base64_encode($encrypted),
                '+/',
                '-_',
            ),
            '=',
        );
    }

    public function createToken(
        string $type,
        string $schoolCode,
        string $assessmentId,
        CarbonImmutable|string|null $expiresAt = null,
    ): string {
        return $this->issueToken(
            $type,
            $schoolCode,
            $assessmentId,
            $expiresAt,
        );
    }

    /**
     * @return array{
     *     version:int,
     *     type:string,
     *     school:string,
     *     assessment_id:string,
     *     expires_at:?string
     * }
     */
    public function resolveToken(
        string $accessToken,
        string $expectedType,
    ): array {
        $accessToken = trim($accessToken);

        if ($accessToken === '') {
            throw new RuntimeException('The external assessment access token is missing.');
        }

        try {
            $encoded = strtr($accessToken, '-_', '+/');
            $remainder = strlen($encoded) % 4;

            if ($remainder !== 0) {
                $encoded .= str_repeat('=', 4 - $remainder);
            }

            $encrypted = base64_decode($encoded, true);

            if ($encrypted === false) {
                throw new RuntimeException('Invalid token encoding.');
            }

            $payload = json_decode(
                Crypt::decryptString($encrypted),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'The external assessment access token is invalid.',
                0,
                $exception,
            );
        }

        if (! is_array($payload)) {
            throw new RuntimeException('The external assessment access token is invalid.');
        }

        $version = (int) ($payload['version'] ?? 0);
        $type = $this->normalizeType((string) ($payload['type'] ?? ''));
        $expectedType = $this->normalizeType($expectedType);
        $schoolCode = strtoupper(trim((string) ($payload['school'] ?? '')));
        $assessmentId = trim((string) ($payload['assessment_id'] ?? ''));
        $expiresAt = trim((string) ($payload['expires_at'] ?? ''));

        if ($version !== 1) {
            throw new RuntimeException('The external assessment access token version is not supported.');
        }

        if ($type !== $expectedType) {
            throw new RuntimeException('This access token cannot be used for this assessment type.');
        }

        if ($schoolCode === '') {
            throw new RuntimeException('The external assessment access token does not contain a school.');
        }

        if (! Str::isUuid($assessmentId)) {
            throw new RuntimeException('The external assessment access token does not contain a valid assessment ID.');
        }

        if ($expiresAt !== '') {
            try {
                $expiry = CarbonImmutable::parse($expiresAt);
            } catch (Throwable $exception) {
                throw new RuntimeException(
                    'The external assessment access token has an invalid expiry.',
                    0,
                    $exception,
                );
            }

            if (CarbonImmutable::now()->greaterThan($expiry)) {
                throw new RuntimeException('The external assessment access link has expired.');
            }
        }

        return [
            'version' => $version,
            'type' => $type,
            'school' => $schoolCode,
            'assessment_id' => $assessmentId,
            'expires_at' => $expiresAt !== '' ? $expiresAt : null,
        ];
    }

    /**
     * @return array{
     *     token:array{
     *         version:int,
     *         type:string,
     *         school:string,
     *         assessment_id:string,
     *         expires_at:?string
     *     },
     *     db:ConnectionInterface
     * }
     */
    public function resolveAccess(
        string $accessToken,
        string $expectedType,
    ): array {
        $token = $this->resolveToken(
            $accessToken,
            $expectedType,
        );

        return [
            'token' => $token,
            'db' => $this->databaseForSchool(
                $token['school'],
            ),
        ];
    }

   public function databaseForSchool(
    string $schoolCode,
    ): ConnectionInterface {
        $schoolCode = strtoupper(trim($schoolCode));

        if ($schoolCode === '') {
            throw new RuntimeException('The school code is missing.');
        }

        $school = config("schools.schools.{$schoolCode}");

        if (! is_array($school)) {
            throw new RuntimeException('The selected school is not configured.');
        }

        $connection = $school['connection'] ?? null;

        if (! is_string($connection) || trim($connection) === '') {
            throw new RuntimeException('The school database connection is missing.');
        }

        $connection = trim($connection);

        if (! is_array(config("database.connections.{$connection}"))) {
            throw new RuntimeException('The school database connection is not configured.');
        }

        return DB::connection($connection);
    }

    public function theoreticalUrl(
        string $schoolCode,
        string $assessmentId,
        CarbonImmutable|string|null $expiresAt = null,
    ): string {
        $token = $this->issueToken(
            self::TYPE_THEORETICAL,
            $schoolCode,
            $assessmentId,
            $expiresAt,
        );

        return $this->externalBaseUrl(
            $schoolCode,
            'theoretical_external_exam_url',
        ).'/external/theoretical/'.rawurlencode($token);
    }

    public function practicalUrl(
        string $schoolCode,
        string $assessmentId,
        CarbonImmutable|string|null $expiresAt = null,
    ): string {
        $token = $this->issueToken(
            self::TYPE_PRACTICAL,
            $schoolCode,
            $assessmentId,
            $expiresAt,
        );

        return $this->externalBaseUrl(
            $schoolCode,
            'practical_external_exam_url',
        ).'/external/practical/'.rawurlencode($token);
    }

    public function assessmentTokenExpiry(
        mixed $accessEndDate,
        mixed $accessEndTime = null,
        int $durationMinutes = 0,
    ): CarbonImmutable {
        $date = trim((string) ($accessEndDate ?? ''));
        $time = trim((string) ($accessEndTime ?? ''));

        if (
            $date === ''
            || $date === '0000-00-00'
            || $date === '1970-01-01'
        ) {
            return CarbonImmutable::now()->addDays(90);
        }

        if ($time === '') {
            $time = '23:59:59';
        }

        try {
            $expiry = CarbonImmutable::parse("{$date} {$time}");
        } catch (Throwable) {
            return CarbonImmutable::now()->addDays(90);
        }

        if ($durationMinutes > 0) {
            $expiry = $expiry->addMinutes($durationMinutes);
        }

        return $expiry->addDay();
    }

    private function externalBaseUrl(
        string $schoolCode,
        string $configKey,
    ): string {
        $schoolCode = strtoupper(trim($schoolCode));

        $configuredUrl = trim(
            (string) config(
                "schools.schools.{$schoolCode}.{$configKey}",
                '',
            ),
        );

        $baseUrl = $configuredUrl !== ''
            ? $configuredUrl
            : trim((string) config('app.url', ''));

        $baseUrl = rtrim($baseUrl, '/');

        if ($baseUrl === '') {
            throw new RuntimeException('The external assessment URL is not configured.');
        }

        return $baseUrl;
    }

    private function normalizeType(
        string $type,
    ): string {
        $type = strtolower(trim($type));

        if (! in_array(
            $type,
            [
                self::TYPE_THEORETICAL,
                self::TYPE_PRACTICAL,
            ],
            true,
        )) {
            throw new RuntimeException('The external assessment type is invalid.');
        }

        return $type;
    }

    private function normalizeExpiry(
        CarbonImmutable|string|null $expiresAt,
    ): ?CarbonImmutable {
        if ($expiresAt === null) {
            return null;
        }

        if ($expiresAt instanceof CarbonImmutable) {
            return $expiresAt;
        }

        $expiresAt = trim($expiresAt);

        if ($expiresAt === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($expiresAt);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'The external assessment access-token expiry is invalid.',
                0,
                $exception,
            );
        }
    }
}
