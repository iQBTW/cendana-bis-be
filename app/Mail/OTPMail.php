<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class OTPMail extends Mailable
{
    use Queueable, SerializesModels;

    public int $otp;

    public function __construct(int $otp)
    {
        $this->otp = $otp;
    }

    public function build()
    {
        $otp = $this->otp;
        $appName = config('app.name', 'Auroapp');
        $expiry = 1; // menit

        $html = <<<HTML
        <!DOCTYPE html>
        <html lang="id">
        <head>
            <meta charset="UTF-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
            <title>Kode OTP</title>
        </head>
        <body style="margin:0;padding:0;background-color:#f4f6f8;font-family:Arial,sans-serif;">
            <table width="100%" cellpadding="0" cellspacing="0" style="background-color:#f4f6f8;padding:40px 0;">
                <tr>
                    <td align="center">
                        <table width="480" cellpadding="0" cellspacing="0"
                               style="background:#ffffff;border-radius:12px;overflow:hidden;
                                      box-shadow:0 2px 8px rgba(0,0,0,0.08);">

                            <!-- Header -->
                            <tr>
                                <td style="background-color:#22c55e;padding:32px 40px;text-align:center;">
                                    <h1 style="margin:0;color:#ffffff;font-size:24px;font-weight:700;
                                               letter-spacing:0.5px;">
                                        $appName
                                    </h1>
                                </td>
                            </tr>

                            <!-- Body -->
                            <tr>
                                <td style="padding:40px;">
                                    <p style="margin:0 0 12px;font-size:16px;color:#374151;">
                                        Halo,
                                    </p>
                                    <p style="margin:0 0 24px;font-size:15px;color:#6b7280;line-height:1.6;">
                                        Gunakan kode OTP berikut untuk memverifikasi akun Anda.
                                        Kode ini berlaku selama <strong>$expiry menit</strong>.
                                    </p>

                                    <!-- OTP Box -->
                                    <div style="background-color:#f0fdf4;border:2px dashed #22c55e;
                                                border-radius:10px;padding:24px;text-align:center;
                                                margin:0 0 24px;">
                                        <p style="margin:0 0 8px;font-size:13px;color:#6b7280;
                                                   letter-spacing:1px;text-transform:uppercase;">
                                            Kode OTP Anda
                                        </p>
                                        <p style="margin:0;font-size:42px;font-weight:700;
                                                   color:#16a34a;letter-spacing:10px;">
                                            $otp
                                        </p>
                                    </div>

                                    <p style="margin:0;font-size:13px;color:#9ca3af;line-height:1.6;">
                                        Jika Anda tidak merasa mendaftar, abaikan email ini.
                                        Jangan bagikan kode ini kepada siapapun.
                                    </p>
                                </td>
                            </tr>

                            <!-- Footer -->
                            <tr>
                                <td style="background-color:#f9fafb;padding:20px 40px;
                                           text-align:center;border-top:1px solid #e5e7eb;">
                                    <p style="margin:0;font-size:12px;color:#9ca3af;">
                                        &copy; 2025 $appName. Seluruh hak dilindungi.
                                    </p>
                                </td>
                            </tr>

                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        HTML;

        return $this->html($html)
            ->subject("Kode OTP Verifikasi - $appName");
    }
}
