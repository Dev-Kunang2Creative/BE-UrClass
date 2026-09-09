<?php

namespace App\Services;

use App\Models\Question;
use App\Models\Tryout;
use App\Models\TryoutSession;
use App\Models\TryoutSubtestSession;
use App\Models\User;
use App\Models\UserAnswer;
use App\Models\UserTryoutAccess;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DummyParticipantService
{
    private const FIRST_NAMES = [
        'Aditya', 'Alya', 'Andini', 'Arga', 'Aulia', 'Bagas', 'Bima', 'Cahya',
        'Daffa', 'Dinda', 'Fajar', 'Farhan', 'Fauzan', 'Gita', 'Hana', 'Indah',
        'Intan', 'Iqbal', 'Kevin', 'Laras', 'Maulana', 'Nabila', 'Nadya', 'Naufal',
        'Putri', 'Rafi', 'Rahma', 'Raka', 'Rania', 'Reza', 'Rizky', 'Salsa',
        'Satria', 'Tiara', 'Vina', 'Wahyu', 'Yasmin', 'Zahra',
    ];

    private const LAST_NAMES = [
        'Adiwijaya', 'Ananda', 'Fadillah', 'Firmansyah', 'Gunawan', 'Halim',
        'Hidayat', 'Kusuma', 'Lestari', 'Mahardika', 'Maulana', 'Nugraha',
        'Nugroho', 'Permana', 'Pradana', 'Pratama', 'Purnama', 'Putra', 'Putri',
        'Ramadhan', 'Salsabila', 'Saputra', 'Setiawan', 'Utami', 'Wijaya', 'Wulandari',
    ];

    private const DEFAULT_SCHOOL_REFERENCES = [
        ['school_name' => 'SMAN 8 Jakarta', 'region_province' => 'DKI Jakarta', 'region_city' => 'Kota Jakarta Selatan'],
        ['school_name' => 'SMAN 1 Yogyakarta', 'region_province' => 'DI Yogyakarta', 'region_city' => 'Kota Yogyakarta'],
        ['school_name' => 'SMAN 3 Bandung', 'region_province' => 'Jawa Barat', 'region_city' => 'Kota Bandung'],
        ['school_name' => 'SMAN 5 Surabaya', 'region_province' => 'Jawa Timur', 'region_city' => 'Kota Surabaya'],
        ['school_name' => 'SMAN 3 Semarang', 'region_province' => 'Jawa Tengah', 'region_city' => 'Kota Semarang'],
        ['school_name' => 'SMAN 1 Denpasar', 'region_province' => 'Bali', 'region_city' => 'Kota Denpasar'],
        ['school_name' => 'SMAN 1 Medan', 'region_province' => 'Sumatera Utara', 'region_city' => 'Kota Medan'],
        ['school_name' => 'SMAN 1 Padang', 'region_province' => 'Sumatera Barat', 'region_city' => 'Kota Padang'],
        ['school_name' => 'SMAN 1 Makassar', 'region_province' => 'Sulawesi Selatan', 'region_city' => 'Kota Makassar'],
        ['school_name' => 'SMAN 1 Balikpapan', 'region_province' => 'Kalimantan Timur', 'region_city' => 'Kota Balikpapan'],
    ];

    public function injectRandom(Tryout $tryout, int $count, string $preset): int
    {
        $references = $this->schoolReferences();

        if ($references->isEmpty()) {
            throw ValidationException::withMessages([
                'reference_data' => ['Data sekolah referensi tidak tersedia.'],
            ]);
        }

        $rows = collect(range(1, $count))->map(function () use ($references, $preset) {
            $reference = $references->random();

            return [
                'name' => $this->indonesianName(),
                'school_id' => $reference['school_id'],
                'school_name' => $reference['school_name'],
                'region_province' => $reference['region_province'],
                'region_city' => $reference['region_city'],
                'score_percentage' => $this->scorePercentage($preset),
            ];
        });

        return $this->createParticipants($tryout, $rows);
    }

    public function injectRows(Tryout $tryout, Collection $rows): int
    {
        return $this->createParticipants($tryout, $rows->map(function (array $row) {
            $schoolName = trim($row['school_name']);
            $province = trim($row['region_province']);
            $city = trim($row['region_city']);

            return [
                'name' => trim($row['name']),
                'school_id' => $this->schoolReferenceId($schoolName, $province, $city),
                'school_name' => $schoolName,
                'region_province' => $province,
                'region_city' => $city,
                'score_percentage' => (float) $row['score_percentage'],
            ];
        }));
    }

    public function clear(Tryout $tryout): int
    {
        ScoringService::forgetIrtWeights($tryout);

        return DB::transaction(function () use ($tryout) {
            $dummyUsers = User::dummy()
                ->whereHas('tryoutAccesses', fn ($query) => $query->where('tryout_id', $tryout->id))
                ->get();

            $count = $dummyUsers->count();

            foreach ($dummyUsers as $dummyUser) {
                $dummyUser->tokens()->delete();
                $dummyUser->delete();
            }

            return $count;
        });
    }

    private function createParticipants(Tryout $tryout, Collection $rows): int
    {
        $questions = $this->questions($tryout);

        if ($questions->isEmpty()) {
            throw ValidationException::withMessages([
                'tryout' => ['Tryout belum memiliki soal aktif. Peserta dummy tidak dapat dibuat tanpa jawaban simulasi.'],
            ]);
        }

        $tryoutSubtests = $tryout->tryoutSubtests()
            ->where('is_active', true)
            ->get()
            ->keyBy('subtest_id');

        $sessions = DB::transaction(function () use ($tryout, $rows, $questions, $tryoutSubtests) {
            return $rows->map(function (array $row) use ($tryout, $questions, $tryoutSubtests) {
                $user = User::create([
                    'name' => $row['name'],
                    'email' => 'dummy_'.strtolower((string) Str::ulid()).'@dummy.urclass.id',
                    'password' => Hash::make(Str::random(64)),
                    'role' => 'user',
                    'kategori' => $tryout->kategori ?: strtolower((string) $tryout->category),
                    'is_dummy' => true,
                ]);

                $user->forceFill([
                    'school_id' => $row['school_id'],
                    'school_name' => $row['school_name'],
                    'school_origin' => $row['school_name'],
                    'region_province' => $row['region_province'],
                    'region_city' => $row['region_city'],
                    'province' => $row['region_province'],
                    'city' => $row['region_city'],
                ])->save();

                UserTryoutAccess::create([
                    'user_id' => $user->id,
                    'tryout_id' => $tryout->id,
                    'granted_at' => now(),
                ]);

                [$startedAt, $finishedAt] = $this->sessionPeriod($tryout);

                $session = TryoutSession::create([
                    'user_id' => $user->id,
                    'tryout_id' => $tryout->id,
                    'attempt_number' => 1,
                    'started_at' => $startedAt,
                    'finished_at' => $finishedAt,
                    'status' => 'finished',
                ]);

                foreach ($tryoutSubtests as $tryoutSubtest) {
                    TryoutSubtestSession::create([
                        'tryout_session_id' => $session->id,
                        'tryout_subtest_id' => $tryoutSubtest->id,
                        'started_at' => $startedAt,
                        'finished_at' => $finishedAt,
                        'status' => 'finished',
                    ]);
                }

                $this->createAnswers($session, $questions, (float) $row['score_percentage']);

                return $session;
            });
        });

        $this->syncSessionScores($tryout, $sessions);

        return $sessions->count();
    }

    private function questions(Tryout $tryout): Collection
    {
        $subtestIds = $tryout->tryoutSubtests()
            ->where('is_active', true)
            ->pluck('subtest_id');

        return Question::with(['subtest', 'options'])
            ->whereIn('subtest_id', $subtestIds)
            ->where('is_active', true)
            ->orderBy('subtest_id')
            ->orderBy('order_no')
            ->get();
    }

    private function createAnswers(TryoutSession $session, Collection $questions, float $targetPercentage): void
    {
        $rightWrongQuestions = $questions->filter(fn (Question $question) => ScoringService::schemeFor($question->subtest) !== ScoringService::SCHEME_OPTION_WEIGHT
        );
        $correctTarget = (int) round($rightWrongQuestions->count() * ($targetPercentage / 100));
        $correctQuestionIds = $rightWrongQuestions
            ->shuffle()
            ->take($correctTarget)
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [(string) $id => true]);

        $answers = $questions->map(function (Question $question) use ($session, $targetPercentage, $correctQuestionIds) {
            $scheme = ScoringService::schemeFor($question->subtest);

            if ($scheme === ScoringService::SCHEME_OPTION_WEIGHT) {
                $jitteredTarget = max(0, min(100, $targetPercentage + random_int(-8, 8)));
                $option = $question->options
                    ->sortBy(fn ($candidate) => abs(((float) $candidate->score / ScoringService::OPTION_WEIGHT_MAX * 100) - $jitteredTarget))
                    ->first();

                $answer = $option?->option_key;
                $isCorrect = (bool) ($option?->is_correct ?? false);
                $score = (float) ($option?->score ?? 0);
            } else {
                $shouldBeCorrect = $correctQuestionIds->has((string) $question->id);
                $answer = $shouldBeCorrect
                    ? $this->correctOptionKey($question)
                    : $this->wrongOptionKey($question);
                $scored = ScoringService::scoreAnswer($question, $question->subtest, $answer);
                $isCorrect = $scored['is_correct'];
                $score = $scored['score'];
            }

            return [
                'tryout_session_id' => $session->id,
                'question_id' => $question->id,
                'answer' => $answer,
                'is_correct' => $isCorrect,
                'score' => $score,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        })->all();

        foreach (array_chunk($answers, 500) as $chunk) {
            UserAnswer::insert($chunk);
        }
    }

    private function correctOptionKey(Question $question): ?string
    {
        if ($question->correct_answer) {
            return (string) $question->correct_answer;
        }

        return $question->options->firstWhere('is_correct', true)?->option_key
            ?? $question->options->sortByDesc('score')->first()?->option_key;
    }

    private function wrongOptionKey(Question $question): ?string
    {
        $correct = $this->correctOptionKey($question);
        $wrongOptions = $question->options
            ->filter(fn ($option) => ! $option->is_correct && strcasecmp((string) $option->option_key, (string) $correct) !== 0)
            ->values();

        if ($wrongOptions->isNotEmpty()) {
            return (string) $wrongOptions->random()->option_key;
        }

        return collect(['A', 'B', 'C', 'D', 'E'])
            ->first(fn (string $key) => strcasecmp($key, (string) $correct) !== 0);
    }

    private function syncSessionScores(Tryout $tryout, Collection $sessions): void
    {
        if ($tryout->use_irt) {
            $subtestIds = $tryout->tryoutSubtests()
                ->where('is_active', true)
                ->pluck('subtest_id');
            $irt = ScoringService::irtWeights($tryout, $subtestIds);
            $rawScores = ScoringService::irtRawScores($sessions->pluck('id'), $irt['weights']);

            foreach ($sessions as $session) {
                $raw = $rawScores[(string) $session->id] ?? 0.0;
                $total = $irt['total'] > 0 ? ($raw / $irt['total']) * 1000 : 0.0;
                $session->update([
                    'raw_score' => round($raw, 2),
                    'total_score' => round($total, 2),
                    'scoring_method' => 'irt',
                    'score_finalized' => ! ($tryout->end_date && now()->lt($tryout->end_date)),
                ]);
            }

            return;
        }

        $aggregates = ScoringService::sessionAggregates($sessions->pluck('id'));
        $maxScore = ScoringService::maxScoreForTryout($tryout);

        foreach ($sessions as $session) {
            $raw = $aggregates[(string) $session->id]['raw_score'] ?? 0.0;
            $total = $tryout->kategori === 'cpns'
                ? $raw
                : ($maxScore > 0 ? ($raw / $maxScore) * 1000 : 0.0);
            $session->update([
                'raw_score' => round($raw, 2),
                'total_score' => round($total, 2),
                'scoring_method' => 'simple',
                'score_finalized' => true,
            ]);
        }
    }

    private function schoolReferences(): Collection
    {
        $fromUsers = User::real()
            ->where('role', 'user')
            ->get([
                'school_id', 'school_name', 'school_origin',
                'region_province', 'region_city', 'province', 'city',
            ])
            ->map(function (User $user) {
                $schoolName = trim((string) ($user->school_name ?: $user->school_origin));
                $province = trim((string) ($user->region_province ?: $user->province));
                $city = trim((string) ($user->region_city ?: $user->city));

                if ($schoolName === '' || $province === '' || $city === '') {
                    return null;
                }

                return [
                    'school_id' => $user->school_id ?: $this->schoolReferenceId($schoolName, $province, $city),
                    'school_name' => $schoolName,
                    'region_province' => $province,
                    'region_city' => $city,
                ];
            })
            ->filter()
            ->unique(fn (array $row) => implode('|', $row))
            ->values();

        if ($fromUsers->isNotEmpty()) {
            return $fromUsers;
        }

        return collect(self::DEFAULT_SCHOOL_REFERENCES)->map(fn (array $ref) => [
            'school_id' => $this->schoolReferenceId($ref['school_name'], $ref['region_province'], $ref['region_city']),
            'school_name' => $ref['school_name'],
            'region_province' => $ref['region_province'],
            'region_city' => $ref['region_city'],
        ]);
    }

    private function schoolReferenceId(string $schoolName, string $province, string $city): string
    {
        return 'dummy-school-'.substr(sha1(Str::lower("{$schoolName}|{$province}|{$city}")), 0, 16);
    }

    private function indonesianName(): string
    {
        return self::FIRST_NAMES[array_rand(self::FIRST_NAMES)].' '
            .self::LAST_NAMES[array_rand(self::LAST_NAMES)];
    }

    private function scorePercentage(string $preset): float
    {
        if ($preset === 'random') {
            return (float) random_int(40, 90);
        }

        [$mean, $deviation, $minimum, $maximum] = $preset === 'competitive'
            ? [82, 6, 70, 95]
            : [68, 9, 45, 90];

        $u1 = max(mt_rand() / mt_getrandmax(), 0.000001);
        $u2 = mt_rand() / mt_getrandmax();
        $standardNormal = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);

        return round(max($minimum, min($maximum, $mean + ($deviation * $standardNormal))), 2);
    }

    private function sessionPeriod(Tryout $tryout): array
    {
        $periodEnd = $tryout->end_date && $tryout->end_date->lt(now())
            ? $tryout->end_date->copy()
            : now();
        $periodStart = $tryout->start_date?->copy() ?? $periodEnd->copy()->subDays(30);

        if ($periodStart->gte($periodEnd)) {
            $periodStart = $periodEnd->copy()->subDays(7);
        }

        $minimumFinish = min($periodStart->timestamp + 1800, $periodEnd->timestamp);
        $finishedAt = Carbon::createFromTimestamp(random_int($minimumFinish, $periodEnd->timestamp));
        $startedAt = $finishedAt->copy()->subMinutes(random_int(45, 150));

        if ($startedAt->lt($periodStart)) {
            $startedAt = $periodStart->copy();
        }

        return [$startedAt, $finishedAt];
    }
}
