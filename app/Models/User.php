<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Builder;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasUlids;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_dummy',
        'kategori',
        'google_id',
        'phone_number',
        'birth_date',
        'gender',
        'school_origin',
        'grade_level',
        'province',
        'city',
        'target_university_1',
        'target_major_1',
        'target_university_2',
        'target_major_2',
        // Target jalur CPNS. Sekolah kedinasan memakai target_university_* dan
        // target_major_* di atas karena bentuknya sama dengan target PTN;
        // pelamar CPNS umum memakai instansi dan formasi di bawah.
        'cpns_target_type',
        'target_instansi_1',
        'target_formasi_1',
        'target_instansi_2',
        'target_formasi_2',
        'ticket_balance',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_dummy' => 'boolean',
        ];
    }

    public function scopeReal(Builder $query): Builder
    {
        return $query->where('is_dummy', false);
    }

    public function scopeDummy(Builder $query): Builder
    {
        return $query->where('is_dummy', true);
    }

    public function setNameAttribute($value): void
    {
        $this->attributes['name'] = $value !== null ? strip_tags(trim($value)) : $value;
    }

    public function setSchoolOriginAttribute($value): void
    {
        $this->attributes['school_origin'] = $value !== null ? strip_tags(trim($value)) : $value;
    }

    public function setGradeLevelAttribute($value): void
    {
        $this->attributes['grade_level'] = $value !== null ? strip_tags(trim($value)) : $value;
    }

    public function setTargetUniversity1Attribute($value): void
    {
        $this->attributes['target_university_1'] = $value !== null ? strip_tags(trim($value)) : $value;
    }

    public function setTargetMajor1Attribute($value): void
    {
        $this->attributes['target_major_1'] = $value !== null ? strip_tags(trim($value)) : $value;
    }

    public function setTargetUniversity2Attribute($value): void
    {
        $this->attributes['target_university_2'] = $value !== null ? strip_tags(trim($value)) : $value;
    }

    public function setTargetMajor2Attribute($value): void
    {
        $this->attributes['target_major_2'] = $value !== null ? strip_tags(trim($value)) : $value;
    }

    public function createdTryouts()
    {
        return $this->hasMany(Tryout::class, 'created_by');
    }

    public function tryoutAccesses(){
        return $this->hasMany(UserTryoutAccess::class);
    }

    public function tryoutSessions()
    {
        return $this->hasMany(TryoutSession::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
    
    public function packageEnrollments()
    {
        return $this->hasMany(UserPackageEnrollment::class);
    }
}
