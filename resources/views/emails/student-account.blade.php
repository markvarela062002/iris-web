<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <title>Your IRIS-SAM Account</title>
</head>

<body
    style="
        margin: 0;
        padding: 0;
        background: #f4f7fb;
        font-family: Arial, Helvetica, sans-serif;
        color: #1e293b;
    "
>
    <table
        role="presentation"
        width="100%"
        cellpadding="0"
        cellspacing="0"
        border="0"
        style="
            width: 100%;
            border-collapse: collapse;
            background: #f4f7fb;
        "
    >
        <tr>
            <td
                align="center"
                style="padding: 32px 16px;"
            >
                <table
                    role="presentation"
                    width="600"
                    cellpadding="0"
                    cellspacing="0"
                    border="0"
                    style="
                        width: 100%;
                        max-width: 600px;
                        border-collapse: separate;
                        border-spacing: 0;
                        background: #ffffff;
                        border: 1px solid #e2e8f0;
                        border-radius: 12px;
                        overflow: hidden;
                    "
                >
                    <tr>
                        <td
                            align="center"
                            style="
                                padding: 28px 24px;
                                background: #123a63;
                            "
                        >
                            <div
                                style="
                                    font-size: 26px;
                                    line-height: 32px;
                                    font-weight: 700;
                                    color: #ffffff;
                                "
                            >
                                IRIS-SAM
                            </div>

                            <div
                                style="
                                    margin-top: 6px;
                                    font-size: 14px;
                                    line-height: 20px;
                                    color: #dbeafe;
                                "
                            >
                                Student Account Credentials
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td
                            style="
                                padding: 30px 28px;
                            "
                        >
                            <p
                                style="
                                    margin: 0 0 16px;
                                    font-size: 16px;
                                    line-height: 24px;
                                "
                            >
                                Hello
                                <strong>{{ $studentName }}</strong>,
                            </p>

                            <p
                                style="
                                    margin: 0 0 22px;
                                    font-size: 14px;
                                    line-height: 22px;
                                    color: #475569;
                                "
                            >
                                Your IRIS-SAM login credentials are shown below.
                                Use the school code together with your username
                                and password when signing in.
                            </p>

                            <table
                                role="presentation"
                                width="100%"
                                cellpadding="0"
                                cellspacing="0"
                                border="0"
                                style="
                                    width: 100%;
                                    border-collapse: collapse;
                                    border: 1px solid #e2e8f0;
                                    background: #f8fafc;
                                "
                            >
                                <tr>
                                    <td
                                        style="
                                            width: 35%;
                                            padding: 12px 14px;
                                            border-bottom: 1px solid #e2e8f0;
                                            font-size: 13px;
                                            color: #64748b;
                                        "
                                    >
                                        Username
                                    </td>

                                    <td
                                        style="
                                            padding: 12px 14px;
                                            border-bottom: 1px solid #e2e8f0;
                                            font-size: 14px;
                                            font-weight: 700;
                                            color: #0f172a;
                                            word-break: break-word;
                                        "
                                    >
                                        {{ $username }}
                                    </td>
                                </tr>

                                <tr>
                                    <td
                                        style="
                                            padding: 12px 14px;
                                            border-bottom: 1px solid #e2e8f0;
                                            font-size: 13px;
                                            color: #64748b;
                                        "
                                    >
                                        Password
                                    </td>

                                    <td
                                        style="
                                            padding: 12px 14px;
                                            border-bottom: 1px solid #e2e8f0;
                                            font-size: 14px;
                                            font-weight: 700;
                                            color: #0f172a;
                                            word-break: break-word;
                                        "
                                    >
                                        {{ $password }}
                                    </td>
                                </tr>

                                <tr>
                                    <td
                                        style="
                                            padding: 12px 14px;
                                            font-size: 13px;
                                            color: #64748b;
                                        "
                                    >
                                        School Code
                                    </td>

                                    <td
                                        style="
                                            padding: 12px 14px;
                                            font-size: 14px;
                                            font-weight: 700;
                                            color: #0f172a;
                                            word-break: break-word;
                                        "
                                    >
                                        {{ $schoolCode }}
                                    </td>
                                </tr>
                            </table>

                            @if ($webUrl !== '')
                                <table
                                    role="presentation"
                                    width="100%"
                                    cellpadding="0"
                                    cellspacing="0"
                                    border="0"
                                    style="
                                        width: 100%;
                                        border-collapse: collapse;
                                        margin-top: 24px;
                                    "
                                >
                                    <tr>
                                        <td align="center">
                                            <a
                                                href="{{ $webUrl }}"
                                                style="
                                                    display: inline-block;
                                                    padding: 12px 22px;
                                                    border-radius: 7px;
                                                    background: #2563eb;
                                                    color: #ffffff;
                                                    font-size: 14px;
                                                    font-weight: 700;
                                                    text-decoration: none;
                                                "
                                            >
                                                Open IRIS-SAM
                                            </a>
                                        </td>
                                    </tr>
                                </table>

                                <p
                                    style="
                                        margin: 14px 0 0;
                                        text-align: center;
                                        font-size: 12px;
                                        line-height: 18px;
                                        color: #64748b;
                                        word-break: break-all;
                                    "
                                >
                                    {{ $webUrl }}
                                </p>
                            @endif

                            @if ($androidUrl !== '' || $appStoreUrl !== '')
                                <div
                                    style="
                                        margin-top: 26px;
                                        padding-top: 20px;
                                        border-top: 1px solid #e2e8f0;
                                    "
                                >
                                    <p
                                        style="
                                            margin: 0 0 10px;
                                            font-size: 13px;
                                            font-weight: 700;
                                            color: #334155;
                                        "
                                    >
                                        Mobile App
                                    </p>

                                    @if ($androidUrl !== '')
                                        <p
                                            style="
                                                margin: 0 0 8px;
                                                font-size: 13px;
                                                line-height: 20px;
                                            "
                                        >
                                            <a
                                                href="{{ $androidUrl }}"
                                                style="
                                                    color: #2563eb;
                                                    text-decoration: none;
                                                    font-weight: 600;
                                                "
                                            >
                                                Download IRIS-SAM on Google Play
                                            </a>
                                        </p>
                                    @endif

                                    @if ($appStoreUrl !== '')
                                        <p
                                            style="
                                                margin: 0;
                                                font-size: 13px;
                                                line-height: 20px;
                                            "
                                        >
                                            <a
                                                href="{{ $appStoreUrl }}"
                                                style="
                                                    color: #2563eb;
                                                    text-decoration: none;
                                                    font-weight: 600;
                                                "
                                            >
                                                Download IRIS-SAM on the App Store
                                            </a>
                                        </p>
                                    @endif
                                </div>
                            @endif

                            <div
                                style="
                                    margin-top: 24px;
                                    padding: 12px 14px;
                                    border: 1px solid #fed7aa;
                                    background: #fff7ed;
                                    font-size: 12px;
                                    line-height: 19px;
                                    color: #9a3412;
                                "
                            >
                                Keep your username, password, and school code
                                private. Do not share these credentials with
                                anyone who should not access your account.
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td
                            align="center"
                            style="
                                padding: 18px 24px;
                                border-top: 1px solid #e2e8f0;
                                background: #f8fafc;
                                font-size: 11px;
                                line-height: 17px;
                                color: #94a3b8;
                            "
                        >
                            IRIS-SAM<br>
                            This is an automated account notification.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
