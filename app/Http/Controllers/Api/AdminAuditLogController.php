<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $logs = AuditLog::with('user:id,name,email')
            ->when($request->module, fn($q, $m) => $q->where('module', $m))
            ->when($request->action, fn($q, $a) => $q->where('action', $a))
            ->when($request->search, fn($q, $s) =>
                $q->where(fn($sub) =>
                    $sub->where('description', 'like', "%{$s}%")
                        ->orWhere('user_name', 'like', "%{$s}%")
                )
            )
            ->when($request->date, fn($q, $d) => $q->whereDate('created_at', $d))
            ->latest()
            ->paginate(20);

        return response()->json($logs);
    }

    public function modules(): JsonResponse
    {
        $modules = AuditLog::distinct()->pluck('module')->sort()->values();
        return response()->json(['data' => $modules]);
    }
}
