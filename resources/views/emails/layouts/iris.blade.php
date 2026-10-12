<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >
    <meta
        name="color-scheme"
        content="light"
    >
    <meta
        name="supported-color-schemes"
        content="light"
    >
    <title>@yield('title', 'IRIS-SAM Notification')</title>
</head>

<body
    style="
        margin: 0;
        padding: 0;
        background-color: #f4f8fd;
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
            margin: 0;
            padding: 0;
            border-collapse: collapse;
            background-color: #f4f8fd;
        "
    >
        <tr>
            <td
                align="center"
                style="padding: 32px 16px;"
            >
                <table
                    role="presentation"
                    width="680"
                    cellpadding="0"
                    cellspacing="0"
                    border="0"
                    style="
                        width: 100%;
                        max-width: 680px;
                        border-collapse: separate;
                        border-spacing: 0;
                        background-color: #ffffff;
                        border: 1px solid #dbe7f3;
                        border-radius: 22px;
                        overflow: hidden;
                    "
                >
                    {{-- HEADER --}}
                    <tr>
                        <td
                            style="
                                padding: 28px 30px;
                                background-color: #0f3d73;
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
                                "
                            >
                                <tr>
                                    {{-- LEFT: IRIS-SAM LOGO --}}
                                    <td
                                        width="25%"
                                        align="left"
                                        valign="middle"
                                        style="
                                            width: 25%;
                                            vertical-align: middle;
                                        "
                                    >
                                        @if (($irisLogoUrl ?? '') !== '')
                                            <img
                                                src="{{ $irisLogoUrl }}"
                                                alt="IRIS-SAM Logo"
                                                style="
                                                    display: block;
                                                    width: auto;
                                                    height: auto;
                                                    max-width: 92px;
                                                    max-height: 92px;
                                                    border: 0;
                                                    outline: none;
                                                "
                                            >
                                        @endif
                                    </td>

                                    {{-- CENTER: IRIS-SAM + NOTIFICATION TYPE --}}
                                    <td
                                        width="50%"
                                        align="center"
                                        valign="middle"
                                        style="
                                            width: 50%;
                                            text-align: center;
                                            vertical-align: middle;
                                        "
                                    >
                                        <div
                                            style="
                                                margin: 0;
                                                font-size: 30px;
                                                line-height: 36px;
                                                font-weight: 700;
                                                color: #ffffff;
                                                letter-spacing: 0.2px;
                                            "
                                        >
                                            IRIS-SAM
                                        </div>

                                        <div
                                            style="
                                                margin-top: 6px;
                                                font-size: 15px;
                                                line-height: 21px;
                                                font-weight: 500;
                                                color: #dbeafe;
                                            "
                                        >
                                            @yield('notification-type', 'Notification')
                                        </div>
                                    </td>

                                    {{-- RIGHT: SCHOOL LOGO --}}
                                    <td
                                        width="25%"
                                        align="right"
                                        valign="middle"
                                        style="
                                            width: 25%;
                                            text-align: right;
                                            vertical-align: middle;
                                        "
                                    >
                                        @if (($schoolLogoUrl ?? '') !== '')
                                            <img
                                                src="{{ $schoolLogoUrl }}"
                                                alt="{{ $schoolName ?? $schoolCode ?? 'School Logo' }}"
                                                style="
                                                    display: block;
                                                    margin-left: auto;
                                                    width: auto;
                                                    height: auto;
                                                    max-width: 110px;
                                                    max-height: 92px;
                                                    border: 0;
                                                    outline: none;
                                                "
                                            >
                                        @endif
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    {{-- BODY --}}
                    <tr>
                        <td
                            style="
                                padding: 32px 30px;
                                background-color: #ffffff;
                            "
                        >
                            @yield('content')
                        </td>
                    </tr>

                    {{-- FOOTER --}}
                    <tr>
                        <td
                            align="center"
                            style="
                                padding: 22px 24px 26px;
                                border-top: 1px solid #dbe7f3;
                                background-color: #f8fbff;
                                text-align: center;
                            "
                        >
                            <div
                                style="
                                    margin-bottom: 9px;
                                    font-size: 13px;
                                    line-height: 18px;
                                    color: #64748b;
                                "
                            >
                                Powered by
                            </div>

                            @if (($elosoftLogoUrl ?? '') !== '')
                                <img
                                    src="{{ $elosoftLogoUrl }}"
                                    alt="ELOSOFT"
                                    style="
                                        display: inline-block;
                                        width: auto;
                                        height: auto;
                                        max-width: 128px;
                                        max-height: 44px;
                                        border: 0;
                                        outline: none;
                                    "
                                >
                            @endif

                            <div
                                style="
                                    margin-top: 12px;
                                    font-size: 12px;
                                    line-height: 18px;
                                    color: #94a3b8;
                                "
                            >
                                IRIS-SAM Student Activity Monitoring System
                                <br>
                                This is an automated notification.
                            </div>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
