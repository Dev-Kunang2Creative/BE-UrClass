<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExamSetting;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExamSettingController extends Controller
{
    public function show(): JsonResponse
    {
        $ambang = ExamSetting::getSkdPassingGrades();

        return response()->json(['data' => [
            'skd_passing_grade_twk' => $ambang['twk'],
            'skd_passing_grade_tiu' => $ambang['tiu'],
            'skd_passing_grade_tkp' => $ambang['tkp'],
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'skd_passing_grade_twk' => ['required', 'integer', 'between:0,65535'],
            'skd_passing_grade_tiu' => ['required', 'integer', 'between:0,65535'],
            'skd_passing_grade_tkp' => ['required', 'integer', 'between:0,65535'],
        ]);
        $pengaturan = ExamSetting::updateOrCreate(['id' => 1], $data);
        AuditLogger::log('ExamSetting', 'update', 'Admin memperbarui ambang batas SKD global.', $request->user(), $pengaturan);

        return $this->show();
    }
}
