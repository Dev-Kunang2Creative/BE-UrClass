<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class AuditLogger
{
    public static function log(
        string $module,
        string $action,
        string $description,
        ?User $user = null,
        ?Model $subject = null
    ): void {
        try {
            AuditLog::create([
                'user_id'      => $user?->id,
                'user_name'    => $user?->name ?? 'System',
                'module'       => $module,
                'action'       => $action,
                'description'  => $description,
                'subject_type' => $subject ? class_basename($subject) : null,
                'subject_id'   => $subject?->id,
                'ip_address'   => request()->ip(),
            ]);
        } catch (\Throwable) {
            // Logging tidak boleh merusak alur utama
        }
    }
}
