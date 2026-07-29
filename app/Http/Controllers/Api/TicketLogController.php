<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TicketLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $logs = TicketLog::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => $logs]);
    }
}
