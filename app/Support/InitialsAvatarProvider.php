<?php

namespace App\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * Avatar inisial dibuat di server sebagai SVG. Pengganti ui-avatars.com bawaan
 * Filament, supaya nama pegawai tidak dikirim ke layanan pihak ketiga.
 */
class InitialsAvatarProvider implements AvatarProvider
{
    public function get(Model $record): string
    {
        $name = str(Filament::getNameForDefaultAvatar($record))->before(',')->trim();
        $initials = $name->explode(' ')->filter(fn ($w) => ctype_alpha(mb_substr($w, 0, 1)))
            ->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64">'
            .'<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#6366f1"/><stop offset="1" stop-color="#4338ca"/></linearGradient></defs>'
            .'<rect width="64" height="64" fill="url(#g)"/>'
            .'<text x="50%" y="50%" dy=".35em" text-anchor="middle" fill="#fff" font-family="Plus Jakarta Sans,Arial,sans-serif" font-size="24" font-weight="700">'
            .e($initials ?: '?').'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
