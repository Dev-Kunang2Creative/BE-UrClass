<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasUlids;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'google_id',
        'phone_number',
        'birth_date',
        'gender',
        'school_origin',
        'grade_level',
        'target_university_1',
        'target_major_1',
        'target_university_2',
        'target_major_2',
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
        ];
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