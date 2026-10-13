<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <style>
        body {
            margin: 0;
            padding: 40px 20px;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #f5f5f5;
        }
        .container {
            max-width: 500px;
            margin: 0 auto;
            background-color: #ffffff;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            padding: 40px 30px;
        }
        .logo {
            text-align: center;
            margin-bottom: 30px;
        }
        .logo img {
            height: 40px;
            width: auto;
        }
        .greeting {
            font-size: 16px;
            color: #333333;
            margin-bottom: 12px;
        }
        .text {
            font-size: 14px;
            color: #555555;
            line-height: 1.7;
            margin-bottom: 28px;
        }
        .button-wrapper {
            text-align: center;
            margin: 32px 0;
        }
        .button {
            background-color: #1a2b6b;
            color: #ffffff !important;
            padding: 12px 24px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 15px;
            font-weight: 600;
            display: inline-block;
        }
        .warning {
            font-size: 13px;
            color: #666666;
            line-height: 1.6;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
        }
        .footer {
            font-size: 11px;
            color: #999999;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px solid #e0e0e0;
            text-align: center;
        }
        a {
            color: #1a2b6b;
        }
    </style>
</head>
<body>

    <div class="container">
        <div class="logo">
            <img src="{{ asset('images/auropay_logo.png') }}" alt="Auro Pay">
        </div>

        <div class="greeting">Hi {{ $name }},</div>

        <div class="text">
            Kami menerima permintaan untuk melakukan reset password pada akun Anda.
            Klik tombol di bawah ini untuk mengatur ulang password Anda.
        </div>

        <div class="button-wrapper">
            <a href="{{ $resetUrl }}" class="button">Ubah Password</a>
        </div>

        <div class="warning">
            Jika Anda tidak meminta penggantian password, Anda dapat mengabaikan email ini dan tidak ada perubahan yang akan dilakukan pada akun Anda.
        </div>

        <div class="footer">
            Copyright © 2025 Design By
            <a href="https://nusantaratama.com" target="_blank">CV. Nusantara Tama</a>
        </div>
    </div>

</body>
</html>
