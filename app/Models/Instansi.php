<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Instansi tujuan pelamar CPNS umum: kementerian, lembaga, atau pemda.
 *
 * Terpisah dari perguruan_tinggi karena bukan sekolah - pelamar CPNS umum
 * melamar ke instansi dan formasi, bukan mendaftar ke kampus dan program studi.
 */
class Instansi extends Model
{
    use HasUlids;

    protected $table = 'instansi';

    protected $fillable = [
        'kode',
        'nama',
        'tingkat',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function formasi()
    {
        return $this->hasMany(Formasi::class)->orderBy('nama');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
