<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ForgetPasswordMail extends Mailable
{
    use Queueable, SerializesModels;

    public $user;
    public $token;

    public function __construct($user, string $token)
    {
        $this->user = $user;
        $this->token = $token;
    }

    public function build()
    {
        return $this->view('emails.forget_password')
                    ->subject('Lupa Password - Auro Pay')
                    ->with([
                        'name' => $this->user->full_name,
                        'resetUrl' => config('app.frontend_url') . '/reset-password/' . $this->token,
                    ]);
    }
}