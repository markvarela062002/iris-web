<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>IRIS-SAM Email Preview</title>

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 32px;
            background: #f4f8fd;
            font-family: Arial, Helvetica, sans-serif;
            color: #1e293b;
        }

        .container {
            width: 100%;
            max-width: 1180px;
            margin: 0 auto;
        }

        .header {
            margin-bottom: 24px;
        }

        .header h1 {
            margin: 0;
            color: #0f172a;
            font-size: 28px;
        }

        .header p {
            margin: 8px 0 0;
            color: #64748b;
            line-height: 1.6;
        }

        .notice {
            margin-bottom: 24px;
            padding: 14px 16px;
            border: 1px solid #bfdbfe;
            border-radius: 12px;
            background: #eff6ff;
            color: #1e40af;
            font-size: 14px;
        }

        .alert {
            margin-bottom: 24px;
            padding: 14px 16px;
            border-radius: 12px;
            font-size: 14px;
        }

        .alert-success {
            border: 1px solid #bbf7d0;
            background: #f0fdf4;
            color: #166534;
        }

        .alert-error {
            border: 1px solid #fecaca;
            background: #fef2f2;
            color: #991b1b;
        }

        .controls {
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
            gap: 16px;
            margin-bottom: 24px;
            padding: 20px;
            border: 1px solid #dbe7f3;
            border-radius: 16px;
            background: #ffffff;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .field label {
            font-size: 13px;
            font-weight: 700;
            color: #475569;
        }

        .field input,
        .field select {
            width: 100%;
            height: 44px;
            padding: 0 12px;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            background: #ffffff;
            color: #0f172a;
            font-size: 14px;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(
                auto-fit,
                minmax(300px, 1fr)
            );
            gap: 18px;
        }

        .card {
            overflow: hidden;
            border: 1px solid #dbe7f3;
            border-radius: 16px;
            background: #ffffff;
        }

        .card-header {
            padding: 18px 20px;
            border-bottom: 1px solid #e2e8f0;
        }

        .card-title {
            margin: 0;
            font-size: 16px;
            color: #0f172a;
        }

        .card-view {
            width: 100%;
            height: 460px;
            border: 0;
            background: #f8fafc;
        }

        .card-actions {
            display: flex;
            gap: 10px;
            padding: 16px 20px;
            border-top: 1px solid #e2e8f0;
        }

        .button {
            display: inline-flex;
            min-height: 40px;
            align-items: center;
            justify-content: center;
            padding: 0 16px;
            border: 0;
            border-radius: 10px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
        }

        .button-primary {
            background: #377ec0;
            color: #ffffff;
        }

        .button-secondary {
            border: 1px solid #cbd5e1;
            background: #ffffff;
            color: #334155;
        }

        .send-form {
            display: inline;
            margin: 0;
        }

        @media (max-width: 700px) {
            body {
                padding: 18px;
            }

            .controls {
                grid-template-columns: 1fr;
            }

            .card-view {
                height: 400px;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <div class="header">
            <h1>IRIS-SAM Email Preview</h1>

            <p>
                Preview and test the actual IRIS-SAM email templates without creating student, assessment, or batch records.
            </p>
        </div>

        <div class="notice">
            This testing page uses sample data only. Sending a test email does not insert or update application records.
        </div>

        @if (session('email_preview_success'))
            <div class="alert alert-success">
                {{ session('email_preview_success') }}
            </div>
        @endif

        @if (session('email_preview_error'))
            <div class="alert alert-error">
                {{ session('email_preview_error') }}
            </div>
        @endif

        @if ($errors->any())
            <div class="alert alert-error">
                {{ $errors->first() }}
            </div>
        @endif

        <div class="controls">
            <div class="field">
                <label for="preview-email">
                    Test Recipient Email
                </label>

                <input
                    id="preview-email"
                    type="email"
                    value="{{ old('email', 'trmf.iris.sam@gmail.com') }}"
                    placeholder="Enter test email"
                >
            </div>

            <div class="field">
                <label for="preview-school">
                    School Branding
                </label>

                <select id="preview-school">
                    @foreach ($schools as $school)
                        <option
                            value="{{ $school['code'] }}"
                            @selected(
                                old(
                                    'school_code',
                                    $defaultSchoolCode,
                                ) === $school['code']
                            )
                        >
                            {{ $school['code'] }} - {{ $school['name'] }}
                        </option>
                    @endforeach
                </select>
            </div>
        </div>

        <div class="grid">
            @foreach ($templates as $templateKey => $template)
                <div class="card">
                    <div class="card-header">
                        <h2 class="card-title">
                            {{ $template['label'] }}
                        </h2>
                    </div>

                    <iframe
                        class="card-view"
                        data-template="{{ $templateKey }}"
                        title="{{ $template['label'] }}"
                    ></iframe>

                    <div class="card-actions">
                        <a
                            class="button button-secondary preview-link"
                            data-template="{{ $templateKey }}"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            Open Full Preview
                        </a>

                        <form
                            class="send-form"
                            method="POST"
                            action="{{ route('email-preview.send') }}"
                        >
                            @csrf

                            <input
                                type="hidden"
                                name="template"
                                value="{{ $templateKey }}"
                            >

                            <input
                                class="email-input"
                                type="hidden"
                                name="email"
                            >

                            <input
                                class="school-input"
                                type="hidden"
                                name="school_code"
                            >

                            <button
                                class="button button-primary"
                                type="submit"
                            >
                                Send Test Email
                            </button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    <script>
        const emailInput = document.getElementById('preview-email');
        const schoolSelect = document.getElementById('preview-school');

        function previewUrl(template) {
            const schoolCode = encodeURIComponent(
                schoolSelect.value,
            );

            return `/email-preview/${template}?school_code=${schoolCode}`;
        }

        function refreshPreviews() {
            document
                .querySelectorAll('.card-view')
                .forEach((iframe) => {
                    iframe.src = previewUrl(
                        iframe.dataset.template,
                    );
                });

            document
                .querySelectorAll('.preview-link')
                .forEach((link) => {
                    link.href = previewUrl(
                        link.dataset.template,
                    );
                });
        }

        function syncForms() {
            document
                .querySelectorAll('.send-form')
                .forEach((form) => {
                    form.querySelector(
                        '.email-input',
                    ).value = emailInput.value;

                    form.querySelector(
                        '.school-input',
                    ).value = schoolSelect.value;
                });
        }

        schoolSelect.addEventListener(
            'change',
            () => {
                refreshPreviews();
                syncForms();
            },
        );

        emailInput.addEventListener(
            'input',
            syncForms,
        );

        refreshPreviews();
        syncForms();
    </script>
</body>
</html>