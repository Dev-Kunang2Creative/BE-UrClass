<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

class ProgramStudi extends Model
{
    use HasUlids;

    protected $table = 'program_studi';

    protected $fillable = [
        'perguruan_tinggi_id',
        'kode_prodi',
        'nama',
        'jenjang',
        'daya_tampung',
        'peminat',
        'jenis_portofolio',
    ];

    protected $casts = [
        'daya_tampung' => 'integer',
        'peminat' => 'integer',
    ];

    protected $appends = ['keketatan'];

    public function perguruanTinggi()
    {
        return $this->belongsTo(PerguruanTinggi::class);
    }

    /**
     * Applicants per seat. Computed rather than stored so it can never drift
     * out of step with the two figures it derives from.
     */
    public function getKeketatanAttribute(): ?float
    {
        if (! $this->daya_tampung || $this->peminat === null) {
            return null;
        }

        return round($this->peminat / $this->daya_tampung, 2);
    }
}
