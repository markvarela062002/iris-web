@extends('emails.layouts.iris')

@section('title', 'Scheduled Practical Assessment')
@section('notification-type', 'Practical Assessment')

@section('content')
    <p style="margin: 0 0 16px; font-size: 16px; line-height: 24px; color: #334155;">
        Hello <strong>{{ $recipientName }}</strong>,
    </p>

    <p style="margin: 0 0 22px; font-size: 14px; line-height: 22px; color: #475569;">
        You have been scheduled for a Practical Assessment. Please review the details below and use the secure assessment button when you are ready.
    </p>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width: 100%; border-collapse: separate; border-spacing: 0; border: 1px solid #dbe7f3; border-radius: 14px; overflow: hidden; background-color: #ffffff;">
        <tr>
            <td style="width: 34%; padding: 14px 18px; background-color: #f8fbff; border-bottom: 1px solid #dbe7f3; font-size: 13px; font-weight: 600; color: #64748b;">Assessment</td>
            <td style="padding: 14px 18px; border-bottom: 1px solid #dbe7f3; font-size: 14px; font-weight: 700; color: #0f172a;">{{ $assessmentTitle }}</td>
        </tr>
        @if (($validityText ?? '') !== '')
            <tr>
                <td style="padding: 14px 18px; background-color: #f8fbff; font-size: 13px; font-weight: 600; color: #64748b;">Validity</td>
                <td style="padding: 14px 18px; font-size: 14px; color: #0f172a;">{{ $validityText }}</td>
            </tr>
        @endif
    </table>

    @if (($assessmentUrl ?? '') !== '')
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin: 24px auto 0; border-collapse: collapse; width: auto;">
            <tr>
                <td align="center" style="border-radius: 12px; background-color: #377ec0;">
                    <a href="{{ $assessmentUrl }}" style="display: inline-block; padding: 13px 24px; border-radius: 12px; background-color: #377ec0; color: #ffffff; font-size: 14px; font-weight: 700; text-decoration: none; white-space: nowrap;">Access Assessment</a>
                </td>
            </tr>
        </table>

        <p style="margin: 20px 0 0; font-size: 12px; line-height: 19px; color: #64748b;">
            <strong>Note:</strong>
            <em>If the button does not open the assessment, copy and paste this secure link into your browser:</em><br>
            <span style="word-break: break-all; color: #377ec0;">{{ $assessmentUrl }}</span>
        </p>
    @endif
@endsection
