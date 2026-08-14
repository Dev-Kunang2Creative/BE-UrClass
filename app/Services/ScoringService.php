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
