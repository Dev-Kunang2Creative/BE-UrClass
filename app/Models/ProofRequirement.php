<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu syarat bukti yang harus dipenuhi peserta tryout gratis.
 *
 * Tiap baris berarti satu slot unggahan dengan instruksinya sendiri: follow
 * sebuah akun, tag beberapa teman, bagikan ke story, atau apa pun yang
 * ditetapkan admin. Jumlah baris aktif menentukan berapa bukti yang diminta
 * server - jadi tidak ada angka yang perlu disamakan di tempat lain.
 *
 * Sebelumnya perannya dipegang tabel akun Instagram, yang hanya bisa menyatakan
 * syarat berbentuk "follow akun X".
 */
class ProofRequirement extends Model
{
    use HasUlids;

    /**
     * Ikon yang dikenali antarmuka. Daftar tertutup supaya nilai asing tidak
     * berakhir sebagai slot tanpa penanda apa pun; kalau tidak cocok, antarmuka
     * memakai bawaannya.
     */
    public const ICONS = [
        'instagram',
        'whatsapp',
        'tiktok',
        'youtube',
        'share',
        'users',
        'camera',
        'link',
    ];

    protected $fillable = [
        'title',
        'instruction',
        'link_url',
        'link_label',
        'icon',
        'order_no',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'order_no' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true)->orderBy('order_no')->orderBy('title');
    }

    /**
     * Tautan Instagram bisa ditulis admin sebagai "@user", "user", atau URL
     * penuh. Ketiganya dibakukan jadi URL supaya yang tersimpan hanya satu
     * bentuk - dan supaya peserta tidak mendapat tautan yang tidak bisa dibuka.
     */
    public static function normaliseLink(?string $value, ?string $icon): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }

        if ($icon === 'instagram') {
            return 'https://www.instagram.com/'.ltrim($value, '@').'/';
        }

        // Bukan URL dan bukan Instagram: kemungkinan besar salah tulis, jadi
        // dibiarkan apa adanya untuk diperbaiki admin daripada ditebak jadi
        // tautan ke tempat yang salah.
        return $value;
    }
}
