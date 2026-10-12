@extends('emails.layouts.iris')

@section('title', 'Your IRIS-SAM Account')
@section('notification-type', 'Student Account Credentials')

@section('content')
    <p style="margin: 0 0 16px; font-size: 16px; line-height: 24px; color: #334155;">
        Hello <strong>{{ $studentName }}</strong>,
    </p>

    <p style="margin: 0 0 24px; font-size: 14px; line-height: 22px; color: #475569;">
        Your IRIS-SAM login credentials are shown below.
        Use your school code together with your username and password when signing in.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%; border-collapse: separate; border-spacing: 0; overflow: hidden; border: 1px solid #dbe7f3; border-radius: 16px; background-color: #ffffff;">
        <tr>
            <td style="width: 34%; padding: 15px 18px; border-bottom: 1px solid #dbe7f3; background-color: #f8fbff; font-size: 13px; font-weight: 600; color: #64748b;">Username</td>
            <td style="padding: 15px 18px; border-bottom: 1px solid #dbe7f3; font-size: 14px; font-weight: 700; color: #0f172a; word-break: break-word;">{{ $username }}</td>
        </tr>
        <tr>
            <td style="padding: 15px 18px; border-bottom: 1px solid #dbe7f3; background-color: #f8fbff; font-size: 13px; font-weight: 600; color: #64748b;">Password</td>
            <td style="padding: 15px 18px; border-bottom: 1px solid #dbe7f3; font-size: 14px; font-weight: 700; color: #0f172a; word-break: break-word;">{{ $password }}</td>
        </tr>
        <tr>
            <td style="padding: 15px 18px; background-color: #f8fbff; font-size: 13px; font-weight: 600; color: #64748b;">School Code</td>
            <td style="padding: 15px 18px; font-size: 14px; font-weight: 700; color: #0f172a; word-break: break-word;">{{ $schoolCode }}</td>
        </tr>
    </table>

    @if (($webUrl ?? '') !== '')
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin: 24px auto 0; border-collapse: collapse; width: auto;">
            <tr>
                <td align="center" style="border-radius: 12px; background-color: #377ec0;">
                    <a href="{{ $webUrl }}" style="display: inline-block; padding: 13px 24px; border-radius: 12px; background-color: #377ec0; color: #ffffff; font-size: 14px; line-height: 20px; font-weight: 700; text-decoration: none; white-space: nowrap;">Access IRIS-SAM</a>
                </td>
            </tr>
        </table>
    @endif

    @if (($androidUrl ?? '') !== '' || ($appStoreUrl ?? '') !== '')
        <div style="margin-top: 26px; padding-top: 22px; border-top: 1px solid #e2e8f0;">
            <p style="margin: 0 0 6px; font-size: 14px; line-height: 21px; font-weight: 700; color: #334155;">Access IRIS-SAM on Mobile</p>
            <p style="margin: 0 0 16px; font-size: 13px; line-height: 20px; color: #64748b;">
                Download the IRIS-SAM mobile app for convenient access on your Android or iOS device.
            </p>

            <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin: 0 auto; border-collapse: collapse; width: auto;">
                <tr>
                    @if (($androidUrl ?? '') !== '')
                        <td align="center" valign="middle" style="padding: 0 6px 0 0;">
                            <a href="{{ $androidUrl }}" style="display: inline-block; padding: 11px 16px; border-radius: 12px; background-color: #377ec0; color: #ffffff; font-size: 13px; line-height: 19px; font-weight: 700; text-decoration: none; white-space: nowrap;">Get it on Google Play</a>
                        </td>
                    @endif
                    @if (($appStoreUrl ?? '') !== '')
                        <td align="center" valign="middle" style="padding: 0 0 0 6px;">
                            <a href="{{ $appStoreUrl }}" style="display: inline-block; padding: 11px 16px; border-radius: 12px; background-color: #0f172a; color: #ffffff; font-size: 13px; line-height: 19px; font-weight: 700; text-decoration: none; white-space: nowrap;">Download on the App Store</a>
                        </td>
                    @endif
                </tr>
            </table>
        </div>
    @endif

    <p style="margin: 24px 0 0; font-size: 12px; line-height: 19px; color: #64748b;">
        <strong>Note:</strong>
        <em>Keep your username, password, and school code private. Do not share these credentials with anyone who should not access your account.</em>
    </p>
@endsection
