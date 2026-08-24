<?php

namespace App\Services;

use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Subtest;
use App\Models\TryoutSession;
use App\Models\UserAnswer;

/**
 * ScoringService — implementasi 3 skema penilaian BRD v1.3 (A-07).
 *
 * SCHEME_IRT           : bobot dinamis dari performa agregat, final setelah periode ditutup
 * SCHEME_RIGHT_WRONG   : satu opsi benar, nilai benar/salah dikonfigurasi admin
 * SCHEME_OPTION_WEIGHT : tiap opsi punya nilai numerik sendiri (TKP CPNS/Kedinasan)
 */
class ScoringService
{
    public const SCHEME_IRT = 'irt';
    public const SCHEME_RIGHT_WRONG = 'right_wrong';
    public const SCHEME_OPTION_WEIGHT = 'option_weight';

    /**
     * Mapping default per exam_type + nama subtes sesuai BRD:
     *   CPNS/Kedinasan  TWK -> Benar/Salah
     *   CPNS/Kedinasan  TIU -> Benar/Salah
     *   CPNS/Kedinasan  TKP -> Bobot per Opsi
     *   UTBK            -> bebas dipilih admin (default Benar/Salah)
     */
    public static function defaultSchemeFor(Subtest $subtest): string
    {
        if ($subtest->exam_type === 'cpns') {
            $name = strtoupper(trim($subtest->name ?? ''));
            if (str_contains($name, 'TKP')) {
                return self::SCHEME_OPTION_WEIGHT;
            }
            return self::SCHEME_RIGHT_WRONG;
        }

        return self::SCHEME_RIGHT_WRONG;
    }

    public static function schemeFor(Subtest $subtest): string
    {
        $scheme = $subtest->scoring_scheme ?? null;

        if (in_array($scheme, [self::SCHEME_IRT, self::SCHEME_RIGHT_WRONG, self::SCHEME_OPTION_WEIGHT], true)) {
            return $scheme;
        }

        return self::defaultSchemeFor($subtest);
    }

    /**
     * Hitung skor satu jawaban sesuai skema subtesnya.
     */
    public static function scoreAnswer(Question $question, Subtest $subtest, ?string $answer): array
    {
        $scheme = self::schemeFor($subtest);

        if ($answer === null || $answer === '') {
            return [
                'is_correct' => false,
                'score' => (float) ($subtest->score_empty ?? 0),
                'scheme' => $scheme,
            ];
        }

        if ($scheme === self::SCHEME_OPTION_WEIGHT) {
            $option = QuestionOption::where('question_id', $question->id)
                ->where('option_key', $answer)
                ->first();

            $score = $option ? (float) $option->score : 0.0;

            return [
                'is_correct' => $option ? (bool) $option->is_correct : false,
                'score' => $score,
                'scheme' => $scheme,
            ];
        }

        $isCorrect = $question->correct_answer !== null
            && strcasecmp(trim($answer), trim((string) $question->correct_answer)) === 0;

        return [
            'is_correct' => $isCorrect,
            'score' => $isCorrect
                ? (float) ($subtest->score_correct ?? 1)
                : (float) ($subtest->score_wrong ?? 0),
            'scheme' => $scheme,
        ];
    }

    /**
     * Total skor mentah satu sesi: jumlah nilai jawaban, bukan jumlah jawaban benar.
     *
     * Untuk subtes right_wrong (1/0) hasilnya identik dengan menghitung jawaban
     * benar. Untuk TKP (option_weight) inilah satu-satunya cara bobot 1-5
     * terhitung.
     */
    public static function rawScoreForSession(TryoutSession $session): float
    {
        return (float) $session->answers()->sum('score');
    }

    /**
     * Skor maksimum satu sesi: jumlah nilai tertinggi tiap soal yang diujikan.
     * Dipakai sebagai penyebut agar skala 0-1000 tetap benar saat satu soal
     * bernilai lebih dari 1 (TKP).
     */
    public static function maxScoreForSession(TryoutSession $session): float
    {
        $subtestIds = $session->tryout
            ?->tryoutSubtests()
            ->where('is_active', true)
            ->pluck('subtest_id') ?? collect();

        if ($subtestIds->isEmpty()) {
            return 0.0;
        }

        $total = 0.0;

        foreach (Subtest::whereIn('id', $subtestIds)->get() as $subtest) {
            $questions = Question::where('subtest_id', $subtest->id)
                ->where('is_active', true)
                ->get();

            foreach ($questions as $question) {
                $total += self::maxScoreForQuestion($question, $subtest);
            }
        }

        return $total;
    }

    /**
     * Skor per subtest untuk satu sesi.
     *
     * Dipakai halaman hasil: peserta CPNS dinilai per ambang (TWK/TIU/TKP), dan
     * satu subtest di bawah ambang membatalkan seluruh SKD berapa pun skor yang
     * lain. Angka agregat saja menyembunyikan satu-satunya hal yang menentukan.
     *
     * Memakai kolom score yang sama dengan rawScoreForSession dan
     * maxScoreForQuestion yang sama dengan maxScoreForSession, supaya rincian
     * ini tidak mungkin berbeda dari totalnya.
     *
     * Ambang lulus sengaja tidak dihitung di sini - itu urusan tampilan, dan
     * menaruhnya di dua tempat berarti dua tempat yang bisa basi.
     */
    public static function perSubtestBreakdown(TryoutSession $session): array
    {
        $subtestIds = $session->tryout
            ?->tryoutSubtests()
            ->where('is_active', true)
            ->pluck('subtest_id') ?? collect();

        if ($subtestIds->isEmpty()) {
            return [];
        }

        // Satu query berkelompok, bukan satu query per subtest: ini jalan di
        // shared hosting dan satu tryout bisa punya tujuh subtest.
        $stats = $session->answers()
            ->join('questions', 'questions.id', '=', 'user_answers.question_id')
            ->whereIn('questions.subtest_id', $subtestIds)
            ->selectRaw('questions.subtest_id as sid')
            ->selectRaw('SUM(user_answers.score) as raw_score')
            ->selectRaw('SUM(CASE WHEN user_answers.is_correct = 1 THEN 1 ELSE 0 END) as correct')
            ->selectRaw('SUM(CASE WHEN user_answers.answer IS NOT NULL THEN 1 ELSE 0 END) as answered')
            ->groupBy('questions.subtest_id')
            ->get()
            ->keyBy('sid');

        $out = [];

        foreach (Subtest::whereIn('id', $subtestIds)->get() as $subtest) {
            $questions = Question::where('subtest_id', $subtest->id)
                ->where('is_active', true)
                ->get();

            $max = 0.0;
            foreach ($questions as $question) {
                $max += self::maxScoreForQuestion($question, $subtest);
            }

            $stat = $stats->get($subtest->id);

            $out[] = [
                'subtest_id' => $subtest->id,
                'name' => $subtest->name,
                'exam_type' => $subtest->exam_type,
                'scheme' => self::schemeFor($subtest),
                'total_questions' => $questions->count(),
                'answered' => (int) ($stat->answered ?? 0),
                'correct' => (int) ($stat->correct ?? 0),
                'raw_score' => round((float) ($stat->raw_score ?? 0), 2),
                'max_score' => round($max, 2),
            ];
        }

        return $out;
    }

    /**
     * Skor maksimum yang mungkin dicapai untuk satu soal.
     */
    public static function maxScoreForQuestion(Question $question, Subtest $subtest): float
    {
        if (self::schemeFor($subtest) === self::SCHEME_OPTION_WEIGHT) {
            return (float) QuestionOption::where('question_id', $question->id)->max('score');
        }

        return (float) ($subtest->score_correct ?? 1);
    }
}
