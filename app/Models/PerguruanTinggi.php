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
    ];

    public function programStudi()
    {
        return $this->hasMany(ProgramStudi::class)->orderBy('nama');
    }
}
