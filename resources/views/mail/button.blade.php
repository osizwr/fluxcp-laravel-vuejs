{{--
    A link styled as a button, with the URL repeated as text underneath.

    The repetition is not redundancy. Some clients will not render the anchor
    as a button, some strip the href from a styled element, and a link that
    cannot be clicked has to be one that can be copied -- otherwise the
    recipient is locked out of their own account.
--}}
<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:22px 0 18px;">
    <tr>
        <td align="center" style="background-color:#2f3448;border-radius:6px;">
            <a href="{{ $url }}"
               style="display:inline-block;padding:12px 22px;color:#ffffff;font-size:16px;font-weight:600;text-decoration:none;">
                {{ $label }}
            </a>
        </td>
    </tr>
</table>

<p style="margin:0 0 18px;color:#6b7080;font-size:13px;line-height:1.5;word-break:break-all;">
    If the button does not work, copy this address into your browser:<br>
    <a href="{{ $url }}" style="color:#2f3448;">{{ $url }}</a>
</p>
