<?php
/** Branded, table-based email markup shared by customer OTP flows. */
function customer_code_email(string $code, string $purpose = 'registration'): string
{
    $reset = $purpose === 'password_reset';
    $title = $reset ? 'Reset your password' : 'Verify your email';
    $intro = $reset
        ? 'Use this code to choose a new password for your Kenji’s Kitchen account.'
        : 'Welcome to Kenji’s Kitchen! Enter this code to verify your email and finish setting up your account.';
    $ignore = $reset
        ? 'If you didn’t request a password reset, you can ignore this email. Your password has not been changed.'
        : 'If you didn’t request this code, you can safely ignore this email.';
    $safeCode = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $year = date('Y');
    // A hosted HTTPS logo avoids MIME image attachments in customer inboxes.
    // Use the public storefront logo by default, including during local development.
    $publicLogo = trim((string) (getenv('EMAIL_LOGO_URL') ?: ($_ENV['EMAIL_LOGO_URL'] ?? '')));
    $logoUrl = 'https://kenjiskitchen.online/assets/img/logo.png';
    if (filter_var($publicLogo, FILTER_VALIDATE_URL) && strtolower((string) parse_url($publicLogo, PHP_URL_SCHEME)) === 'https') {
        $logoUrl = htmlspecialchars($publicLogo, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    return <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>{$title} | Kenji’s Kitchen</title></head>
<body style="margin:0;padding:0;background-color:#f6f3eb;color:#29251e;font-family:Arial,Helvetica,sans-serif;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">Your Kenji’s Kitchen verification code expires in 5 minutes.</div>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#f6f3eb"><tr><td align="center" style="padding:32px 16px;">
<table role="presentation" width="520" cellspacing="0" cellpadding="0" border="0" style="width:100%;max-width:520px;background-color:#ffffff;border:1px solid #e6dfcf;border-radius:18px;overflow:hidden;">
<tr><td bgcolor="#f2c12e" style="padding:24px 28px;border-radius:18px 18px 0 0;color:#29251e;"><table role="presentation" cellspacing="0" cellpadding="0" border="0"><tr><td width="64" valign="middle" style="padding-right:12px;"><img src="{$logoUrl}" width="52" height="52" alt="Kenji’s Kitchen logo" style="display:block;border:0;border-radius:12px;"></td><td valign="middle"><p style="margin:0;font-size:22px;line-height:1.3;font-weight:800;">Kenji’s Kitchen</p><p style="margin:6px 0 0;font-size:12px;line-height:1.5;">Greater Lagro, Quezon City</p></td></tr></table></td></tr>
<tr><td style="padding:30px 28px;background-color:#ffffff;color:#29251e;">
<p style="margin:0 0 10px;font-size:11px;font-weight:700;letter-spacing:1.5px;color:#806019;">YOUR CUSTOMER ACCOUNT</p>
<h1 style="margin:0 0 14px;font-size:26px;line-height:1.3;color:#29251e;">{$title}</h1>
<p style="margin:0 0 24px;font-size:15px;line-height:1.7;color:#635b4e;">{$intro}</p>
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" bgcolor="#fff5d6" style="padding:20px 12px;border:1px solid #e6d39a;border-radius:12px;">
<p style="margin:0 0 8px;font-size:11px;font-weight:700;letter-spacing:1px;color:#806019;">YOUR VERIFICATION CODE</p>
<p style="margin:0;font-family:Consolas,monospace;font-size:34px;line-height:1.4;font-weight:700;letter-spacing:6px;color:#29251e;">{$safeCode}</p>
</td></tr></table>
<p style="margin:18px 0 24px;text-align:center;font-size:13px;line-height:1.6;color:#635b4e;">Valid for <strong>5 minutes</strong>. Don’t share this code with anyone.</p>
<p style="margin:0;padding-top:20px;border-top:1px solid #e6dfcf;font-size:13px;line-height:1.7;color:#635b4e;">{$ignore}</p>
</td></tr>
<tr><td bgcolor="#faf8f2" style="padding:18px 28px;border-radius:0 0 18px 18px;font-size:12px;line-height:1.7;color:#716757;">Everyday meals. Presyong sulit.<br>&copy; {$year} Kenji’s Kitchen</td></tr>
</table></td></tr></table></body></html>
HTML;
}
