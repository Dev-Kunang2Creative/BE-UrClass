<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class PerguruanTinggi extends Model
{
    use HasUlids;

    // Indonesian nouns do not pluralise, and Laravel would guess
    // "perguruan_tinggis".
    protected $table = 'perguruan_tinggi';

    protected $fillable = [
        'kode_ptn',
        'nama',
        'jenis',
    ];

    /**
     * Sekolah kedinasan memakai tabel yang sama karena bentuk targetnya identik
     * dengan PTN - sekolah plus program studi - jadi picker, endpoint, dan kolom
     * profil yang sudah ada bisa dipakai apa adanya. Yang membedakan hanya jenis.
     */
    public function scopeJenis($query, ?string $jenis)
    {
        return $jenis ? $query->where('jenis', $jenis) : $query;
    }

    public function programStudi()
    {
        return $this->hasMany(ProgramStudi::class)->orderBy('nama');
    }
}
