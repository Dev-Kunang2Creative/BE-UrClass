<?php

namespace App\Services;

use App\Models\Tryout;
use App\Models\TryoutSession;
use Illuminate\Support\Collection;

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
        $isFullSkd = ScoringService::isFullSkd($tryout);
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

        if (! $isFullSkd) {
            $query->orderByDesc('tryout_sessions.total_score')
                ->orderBy('tryout_sessions.finished_at')
                ->limit($limit);
        }

        $rows = $query->get();
        $skdStatuses = $isFullSkd
            ? ScoringService::skdPassingStatuses($tryout, $rows->pluck('id'))
            : [];

        $entries = $rows->values()->map(function ($row) use ($isFullSkd, $skdStatuses) {
            $entry = [
                'user_id' => $row->user_id,
                'user_name' => $row->user_name,
                'score' => (float) $row->total_score,
                'school_id' => $row->school_id,
                'school_name' => $row->school_name,
                'region_province' => $row->region_province,
                'region_city' => $row->region_city,
                'finished_at' => $row->finished_at,
            ];

            if ($isFullSkd) {
                $status = $skdStatuses[(string) $row->id];
                $entry['is_passed'] = $status['is_passed_skd'];
                $entry['twk_score'] = $status['scores']['twk'];
                $entry['tiu_score'] = $status['scores']['tiu'];
                $entry['tkp_score'] = $status['scores']['tkp'];
                $entry['score'] = array_sum($status['scores']);
            }

            return $entry;
        });

        if ($isFullSkd) {
            $entries = $entries->sort(fn ($left, $right) => self::compareEntries($left, $right, true));
        }

        return $entries
            ->take($limit)
            ->values()
            ->map(function ($entry, $index) {
                $entry['rank'] = $index + 1;

                return $entry;
            });
    }

    /**
     * Pilih percobaan terbaik per peserta lalu beri peringkat dengan aturan
     * yang sama untuk endpoint siswa dan pemanggil service.
     */
    public static function rankBestAttempts(Collection $rows, bool $isFullSkd): Collection
    {
        $compare = fn ($left, $right) => self::compareEntries($left, $right, $isFullSkd);

        return $rows
            ->groupBy('user_id')
            ->map(fn (Collection $attempts) => $attempts->sort($compare)->first())
            ->values()
            ->sort($compare)
            ->values()
            ->map(function ($row, $index) {
                $row['rank'] = $index + 1;

                return $row;
            });
    }

    private static function compareEntries($left, $right, bool $isFullSkd): int
    {
        $descendingKeys = $isFullSkd
            ? ['is_passed', 'score.final_score', 'tkp_score', 'tiu_score', 'twk_score']
            : ['score.final_score', 'summary.correct'];

        foreach ($descendingKeys as $key) {
            $leftValue = data_get($left, $key);
            $rightValue = data_get($right, $key);

            if ($key === 'score.final_score') {
                $leftValue ??= data_get($left, 'score');
                $rightValue ??= data_get($right, 'score');
            }

            $comparison = (float) $rightValue <=> (float) $leftValue;
            if ($comparison !== 0) {
                return $comparison;
            }
        }

        $finishedComparison = strcmp(
            (string) data_get($left, 'finished_at'),
            (string) data_get($right, 'finished_at'),
        );

        return $finishedComparison !== 0
            ? $finishedComparison
            : strcmp((string) data_get($left, 'user_id'), (string) data_get($right, 'user_id'));
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
