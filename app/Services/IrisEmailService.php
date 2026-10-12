<?php

namespace App\Services;

use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;

class IrisEmailService
{
    public function send(
        string $view,
        string $email,
        string $recipientName,
        string $schoolCode,
        string $subject,
        array $data = [],
    ): void {
        $email = trim($email);

        if (
            $email === ''
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false
        ) {
            throw new InvalidArgumentException(
                'A valid recipient email address is required.',
            );
        }

        $viewData = $this->viewData(
            recipientName: $recipientName,
            schoolCode: $schoolCode,
            subject: $subject,
            data: $data,
        );

        Mail::send(
            $view,
            $viewData,
            function ($message) use (
                $email,
                $recipientName,
                $subject,
            ): void {
                $recipientName = trim($recipientName);
                $subject = trim($subject);

                if ($recipientName !== '') {
                    $message->to(
                        $email,
                        $recipientName,
                    );
                } else {
                    $message->to($email);
                }

                $message->subject(
                    $subject !== ''
                        ? $subject
                        : 'IRIS-SAM Notification',
                );

                $bcc = $this->bccAddresses();

                if ($bcc !== []) {
                    $message->bcc($bcc);
                }
            },
        );
    }

    public function render(
        string $view,
        string $recipientName,
        string $schoolCode,
        string $subject,
        array $data = [],
    ): string {
        return view(
            $view,
            $this->viewData(
                recipientName: $recipientName,
                schoolCode: $schoolCode,
                subject: $subject,
                data: $data,
            ),
        )->render();
    }

    private function viewData(
        string $recipientName,
        string $schoolCode,
        string $subject,
        array $data,
    ): array {
        $recipientName = trim($recipientName);
        $schoolCode = strtoupper(trim($schoolCode));
        $subject = trim($subject);

        if ($schoolCode === '') {
            throw new InvalidArgumentException(
                'A school code is required to send an IRIS-SAM email.',
            );
        }

        $school = config("schools.schools.{$schoolCode}");

        if (! is_array($school)) {
            throw new InvalidArgumentException(
                "School configuration is unavailable for {$schoolCode}.",
            );
        }

        $schoolName = trim(
            (string) ($school['name'] ?? $schoolCode),
        );

        if ($schoolName === '') {
            $schoolName = $schoolCode;
        }

        $schoolLogoPath = trim(
            (string) ($school['logo'] ?? ''),
        );

        $irisLogoPath = trim(
            (string) config(
                'mail.iris.logo',
                '/images/iris.png',
            ),
        );

        $elosoftLogoPath = trim(
            (string) config(
                'mail.iris.elosoft_logo',
                '/images/es.png',
            ),
        );

        $webUrl = $this->nonEmptyConfigValue(
            'mail.iris.web_url',
            (string) config('app.url', ''),
        );

        $androidUrl = $this->nonEmptyConfigValue(
            'mail.iris.android_url',
            'https://play.google.com/store/apps/details?id=com.elosoftbiz.iris',
        );

        $appStoreUrl = $this->nonEmptyConfigValue(
            'mail.iris.app_store_url',
            'https://apps.apple.com/ph/app/iris-sams/id6802582212',
        );

        $brandData = [
            'subject' => $subject,
            'recipientName' => $recipientName,
            'schoolCode' => $schoolCode,
            'schoolName' => $schoolName,
            'irisLogoUrl' => $this->emailAssetUrl($irisLogoPath),
            'schoolLogoUrl' => $this->emailAssetUrl($schoolLogoPath),
            'elosoftLogoUrl' => $this->emailAssetUrl($elosoftLogoPath),
            'webUrl' => rtrim($webUrl, '/'),
            'androidUrl' => $androidUrl,
            'appStoreUrl' => $appStoreUrl,
        ];

        return array_merge(
            $brandData,
            $data,
        );
    }

    private function nonEmptyConfigValue(
        string $key,
        string $fallback,
    ): string {
        $value = trim(
            (string) config(
                $key,
                '',
            ),
        );

        return $value !== ''
            ? $value
            : $fallback;
    }

    private function emailAssetUrl(
        string $path,
    ): string {
        $path = trim($path);

        if ($path === '') {
            return '';
        }

        if (
            str_starts_with($path, 'https://')
            || str_starts_with($path, 'http://')
        ) {
            return $path;
        }

        $assetBaseUrl = $this->nonEmptyConfigValue(
            'mail.iris.asset_url',
            (string) config('app.url', ''),
        );

        $assetBaseUrl = rtrim(
            trim($assetBaseUrl),
            '/',
        );

        if ($assetBaseUrl === '') {
            return '';
        }

        return $assetBaseUrl
            .'/'
            .ltrim($path, '/');
    }

    /**
     * @return array<int, string>
     */
    private function bccAddresses(): array
    {
        $configured = config(
            'mail.iris.bcc',
            [],
        );

        if (! is_array($configured)) {
            return [];
        }

        return array_values(
            array_filter(
                array_map(
                    static fn (mixed $address): string =>
                        is_string($address)
                            ? trim($address)
                            : '',
                    $configured,
                ),
                static fn (string $address): bool =>
                    $address !== ''
                    && filter_var(
                        $address,
                        FILTER_VALIDATE_EMAIL,
                    ) !== false,
            ),
        );
    }
}