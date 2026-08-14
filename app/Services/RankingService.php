<?php

namespace App\Services;

use App\Models\Tryout;
use App\Models\TryoutSession;
use Illuminate\Support\Facades\DB;

/**
 * RankingService — leaderboard 3 level sesuai BRD v1.3 (P-10).
 *
 * Level: national | region | school
 * Untuk tryout IRT, ranking hanya dipublikasikan setelah periode ditutup.
 */
class RankingService
{
    public const LEVEL_NATIONAL = 'national';
    public const LEVEL_REGION = 'region';
    public const LEVEL_SCHOOL = 'school';

    /**
     * Apakah hasil/ranking tryout sudah boleh dipublikasikan.
     */
    public static function isPublishable(Tryout $tryout): bool
    {
        if (! $tryout->use_irt) {
            return true;
        }

        return ! ($tryout->end_date && now()->lt($tryout->end_date));
    }

    /**
     * Ambil leaderboard untuk satu tryout pada level tertentu.
     *
     * @param  string  $level  national|region|school
     * @param  array   $scope  ['region_province' => ..., 'school_id' => ...]
     */
    public static function leaderboard(Tryout $tryout, string $level = self::LEVEL_NATIONAL, array $scope = [], int $limit = 100)
    {
        $query = TryoutSession::query()
            ->select([
                'tryout_sessions.id',
                'tryout_sessions.user_id',
                'tryout_sessions.total_score',
                'tryout_sessions.finished_at',
                'users.name as user_name',
                'users.school_id',
                'users.school_name',
                'users.region_province',
                'users.region_city',
            ])
            ->join('users', 'users.id', '=', 'tryout_sessions.user_id')
            ->where('tryout_sessions.tryout_id', $tryout->id)
            ->where('tryout_sessions.status', 'finished')
            ->where('tryout_sessions.attempt_number', 1);

        if ($level === self::LEVEL_REGION) {
            $province = $scope['region_province'] ?? null;
            if ($province) {
                $query->where('users.region_province', $province);
            } else {
                $query->whereNotNull('users.region_province');
            }
        }

        if ($level === self::LEVEL_SCHOOL) {
            $schoolId = $scope['school_id'] ?? null;
            if ($schoolId) {
                $query->where('users.school_id', $schoolId);
            } else {
                $query->whereNotNull('users.school_id');
            }
        }

        $rows = $query
            ->orderByDesc('tryout_sessions.total_score')
            ->orderBy('tryout_sessions.finished_at')
            ->limit($limit)
            ->get();

        return $rows->values()->map(function ($row, $index) {
            return [
                'rank' => $index + 1,
                'user_id' => $row->user_id,
                'user_name' => $row->user_name,
                'score' => (float) $row->total_score,
                'school_id' => $row->school_id,
                'school_name' => $row->school_name,
                'region_province' => $row->region_province,
                'region_city' => $row->region_city,
                'finished_at' => $row->finished_at,
            ];
        });
    }

    /**
     * Posisi peringkat seorang user pada level tertentu.
     */
    public static function userRank(Tryout $tryout, $userId, string $level = self::LEVEL_NATIONAL, array $scope = []): ?array
    {
        $board = self::leaderboard($tryout, $level, $scope, 10000);

        foreach ($board as $entry) {
            if ((string) $entry['user_id'] === (string) $userId) {
                return [
                    'rank' => $entry['rank'],
                    'total' => $board->count(),
                    'score' => $entry['score'],
                    'level' => $level,
                ];
            }
        }

        return null;
    }
}
