<?php

namespace App\Http\Controllers;

use App\Services\IrisEmailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Throwable;

class EmailPreviewController extends Controller
{
    public function __construct(
        private readonly IrisEmailService $irisEmailService,
    ) {
    }

    public function index(): Response
    {
        return response()->view(
            'email-preview.index',
            [
                'templates' => $this->templates(),
                'schools' => $this->schoolOptions(),
                'defaultSchoolCode' => $this->defaultSchoolCode(),
            ],
        );
    }

    public function preview(
        Request $request,
        string $template,
    ): Response {
        $templates = $this->templates();

        abort_unless(
            array_key_exists($template, $templates),
            Response::HTTP_NOT_FOUND,
        );

        $schoolCode = strtoupper(
            trim(
                (string) $request->query(
                    'school_code',
                    $this->defaultSchoolCode(),
                ),
            ),
        );

        $this->ensureValidSchoolCode($schoolCode);

        $definition = $templates[$template];

        $html = $this->irisEmailService->render(
            view: $definition['view'],
            recipientName: $definition['recipient_name'],
            schoolCode: $schoolCode,
            subject: $definition['subject'],
            data: $definition['data'],
        );

        return response(
            $html,
            Response::HTTP_OK,
            [
                'Content-Type' => 'text/html; charset=UTF-8',
            ],
        );
    }

    public function send(Request $request): RedirectResponse
    {
        $templates = $this->templates();
        $schoolCodes = array_keys(
            config('schools.schools', []),
        );

        $validated = $request->validate([
            'template' => [
                'required',
                'string',
                Rule::in(array_keys($templates)),
            ],
            'email' => [
                'required',
                'email:rfc',
                'max:255',
            ],
            'school_code' => [
                'required',
                'string',
                Rule::in($schoolCodes),
            ],
        ]);

        $definition = $templates[
            $validated['template']
        ];

        try {
            $this->irisEmailService->send(
                view: $definition['view'],
                email: $validated['email'],
                recipientName: $definition['recipient_name'],
                schoolCode: $validated['school_code'],
                subject: '[TEST] '.$definition['subject'],
                data: $definition['data'],
            );
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->with(
                    'email_preview_error',
                    'The test email could not be sent. Check storage/logs/laravel.log for the exact mail error.',
                );
        }

        return back()->with(
            'email_preview_success',
            sprintf(
                '%s test email sent successfully to %s.',
                $definition['label'],
                $validated['email'],
            ),
        );
    }

    private function templates(): array
    {
        return [
            'student-account' => [
                'label' => 'Student Account Credentials',
                'view' => 'emails.student-account',
                'subject' => 'Your IRIS-SAM account',
                'recipient_name' => 'Test Student',
                'data' => [
                    'studentName' => 'Test Student',
                    'username' => 'TEST001',
                    'password' => 'Test123',
                ],
            ],
            'practical-internal' => [
                'label' => 'Practical Assessment - Internal',
                'view' => 'emails.practical-internal',
                'subject' => 'You have a scheduled Practical Assessment on IRIS-SAM',
                'recipient_name' => 'Test Student',
                'data' => [
                    'assessmentTitle' => 'Sample Practical Assessment',
                    'fromDate' => 'Oct 09, 2026',
                    'dueDate' => 'Oct 10, 2026',
                ],
            ],
            'practical-external' => [
                'label' => 'Practical Assessment - External',
                'view' => 'emails.practical-external',
                'subject' => 'You have a scheduled Practical Assessment',
                'recipient_name' => 'Test Examinee',
                'data' => [
                    'assessmentTitle' => 'Sample External Practical Assessment',
                    'validityText' => 'Available from Oct 09, 2026 until Oct 10, 2026',
                    'assessmentUrl' => 'https://iris.trmfoundation.com/external/practical/test-preview-token',
                ],
            ],
            'theoretical-internal' => [
                'label' => 'Theoretical Assessment - Internal',
                'view' => 'emails.theoretical-internal',
                'subject' => 'You have a scheduled Theoretical Assessment on IRIS-SAM',
                'recipient_name' => 'Test Student',
                'data' => [
                    'examName' => 'Sample Theoretical Exam Package',
                    'accessUntil' => 'Oct 10, 2026 17:00',
                ],
            ],
            'theoretical-external' => [
                'label' => 'Theoretical Assessment - External',
                'view' => 'emails.theoretical-external',
                'subject' => 'You have a scheduled Theoretical Assessment',
                'recipient_name' => 'Test Examinee',
                'data' => [
                    'examName' => 'Sample External Theoretical Exam',
                    'accessUntil' => 'Oct 10, 2026 17:00',
                    'assessmentUrl' => 'https://iris.trmfoundation.com/external/theoretical/test-preview-token',
                ],
            ],
        ];
    }

    private function schoolOptions(): array
    {
        return collect(
            config('schools.schools', []),
        )
            ->map(
                function (
                    mixed $school,
                    string $schoolCode,
                ): array {
                    $name = is_array($school)
                        ? trim(
                            (string) (
                                $school['name']
                                ?? $schoolCode
                            ),
                        )
                        : $schoolCode;

                    return [
                        'code' => $schoolCode,
                        'name' => $name !== ''
                            ? $name
                            : $schoolCode,
                    ];
                },
            )
            ->values()
            ->all();
    }

    private function defaultSchoolCode(): string
    {
        $schools = config(
            'schools.schools',
            [],
        );

        if (
            is_array($schools)
            && array_key_exists('UPHSD', $schools)
        ) {
            return 'UPHSD';
        }

        return is_array($schools)
            ? (string) array_key_first($schools)
            : '';
    }

    private function ensureValidSchoolCode(
        string $schoolCode,
    ): void {
        $schools = config(
            'schools.schools',
            [],
        );

        abort_unless(
            is_array($schools)
            && array_key_exists(
                $schoolCode,
                $schools,
            ),
            Response::HTTP_NOT_FOUND,
            'The selected school is not configured.',
        );
    }
}