<?php

namespace App\Support;

/**
 * Sidik lampiran izin.
 *
 * - exact(): SHA-256 isi file; sama persis berarti file yang sama.
 * - perceptual(): dHash 64-bit untuk gambar. Foto yang sama tetap mirip
 *   walau dikompres ulang, diperkecil, atau diteruskan lewat WhatsApp,
 *   sedangkan SHA-256-nya berbeda. Karena surat dari template yang sama juga
 *   bisa tampak mirip, hasil ini hanya dipakai untuk memberi tanda kepada
 *   penyetuju, bukan untuk menolak.
 */
class AttachmentFingerprint
{
    /** Batas jumlah bit berbeda (dari 64) agar dua gambar dianggap mirip. */
    public const SIMILAR_MAX_DISTANCE = 6;

    /** Gambar lebih besar dari ini tidak dibuka (GD memakai ±4 byte per piksel). */
    public const MAX_PIXELS = 12_000_000;

    public static function exact(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    /** dHash 16 karakter hex, atau null bila bukan gambar yang bisa dibaca. */
    public static function perceptual(string $bytes): ?string
    {
        if (! function_exists('imagecreatefromstring')) {
            return null;
        }
        $size = @getimagesizefromstring($bytes);
        if (! $size || $size[0] * $size[1] > self::MAX_PIXELS) {
            return null;
        }
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }
        // 9×8 piksel abu-abu: tiap baris membandingkan 8 pasang piksel bertetangga.
        $small = imagecreatetruecolor(9, 8);
        imagecopyresampled($small, $src, 0, 0, 0, 0, 9, 8, imagesx($src), imagesy($src));
        imagedestroy($src);

        $bits = '';
        for ($y = 0; $y < 8; $y++) {
            $prev = null;
            for ($x = 0; $x < 9; $x++) {
                $rgb = imagecolorat($small, $x, $y);
                $gray = 0.299 * (($rgb >> 16) & 0xFF) + 0.587 * (($rgb >> 8) & 0xFF) + 0.114 * ($rgb & 0xFF);
                if ($prev !== null) {
                    $bits .= $prev > $gray ? '1' : '0';
                }
                $prev = $gray;
            }
        }
        imagedestroy($small);

        $hex = '';
        foreach (str_split($bits, 4) as $nibble) {
            $hex .= dechex(bindec($nibble));
        }

        return $hex;
    }

    /** Jumlah bit berbeda antara dua dHash. */
    public static function distance(string $a, string $b): int
    {
        $diff = 0;
        foreach ([0, 8] as $offset) {
            $diff += substr_count(decbin(hexdec(substr($a, $offset, 8)) ^ hexdec(substr($b, $offset, 8))), '1');
        }

        return $diff;
    }
}
