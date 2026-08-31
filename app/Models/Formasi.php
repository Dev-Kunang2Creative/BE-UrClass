<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu formasi/jabatan yang dibuka sebuah instansi.
 *
 * Setara program_studi bagi jalur kedinasan: pilihan tingkat kedua setelah
 * instansinya dipilih, sehingga daftarnya bisa dipersempit.
 */
class Formasi extends Model
{
    use HasUlids;

    protected $table = 'formasi';

    protected $fillable = [
        'instansi_id',
        'nama',
        'jenjang',
        // Tahun seleksi formasi ini diterbitkan. Formasi terbit per periode,
        // jadi tanpa kolom ini daftar dari tahun berbeda tidak bisa dipisahkan.
        'periode',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'periode' => 'integer',
    ];

    public function instansi()
    {
        return $this->belongsTo(Instansi::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
