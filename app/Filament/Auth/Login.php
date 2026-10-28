<?php

namespace App\Filament\Auth;

use Filament\Auth\Pages\Login as BaseLogin;

class Login extends BaseLogin
{
    public function getHeading(): string
    {
        return 'Selamat datang kembali';
    }

    public function getSubheading(): string
    {
        return 'Masuk menggunakan akun yang diberikan Bagian SDM.';
    }
}
