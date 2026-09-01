<?php

namespace App\Services;

use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Subtest;
use App\Models\Tryout;
use App\Models\TryoutSession;
use App\Models\UserAnswer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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
     * Skala bobot TKP: tiap opsi bernilai 1-5, tidak ada opsi bernilai 0.
     */
    public const OPTION_WEIGHT_MIN = 1;
    public const OPTION_WEIGHT_MAX = 5;

    /**
     * Nilai per soal untuk TWK/TIU SKD: benar 5, salah dan kosong 0, sehingga
     * 30 soal TWK bernilai maksimal 150 dan 35 soal TIU 175 - angka yang
     * dipakai Passing Grade KepmenPAN-RB (TWK 65, TIU 80, TKP 166).
     */
    public const CPNS_SCORE_CORRECT = 5;

    /**
     * Skema penilaian ditentukan jalurnya, bukan dipilih bebas:
     *   UTBK            -> IRT selalu
     *   CPNS  TKP       -> Bobot per Opsi
     *   CPNS  selainnya -> Benar/Salah
     *
     * UTBK dikunci ke IRT karena memang begitu ujiannya dinilai: jawaban
     * dihitung benar/salah, lalu bobot tiap soal diturunkan dari hasil seluruh
     * peserta. Tidak ada poin benar/salah yang perlu ditetapkan admin.
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

        return self::SCHEME_IRT;
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

        // IRT menilai benar/salah juga, tapi tanpa poin yang ditetapkan admin:
        // yang disimpan sekadar tanda benar (1) atau salah (0). Bobot tiap soal
        // baru dihitung belakangan dari hasil seluruh peserta, jadi memakai
        // score_correct di sini akan mengarang angka yang tidak dipakai apa pun.
        if ($scheme === self::SCHEME_IRT) {
            return [
                'is_correct' => $isCorrect,
                'score' => $isCorrect ? 1.0 : 0.0,
                'scheme' => $scheme,
            ];
        }

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
     *
     * Nilainya sifat tryout, bukan sifat sesi - dua peserta di tryout yang sama
     * punya penyebut yang sama. Karena itu pemanggil yang mengolah banyak sesi
     * sekaligus sebaiknya memanggil maxScoreForTryout() sekali di luar loop;
     * memanggil yang ini per sesi berarti mengulang pekerjaan yang sama sebanyak
     * jumlah peserta.
     */
    public static function maxScoreForSession(TryoutSession $session): float
    {
        return $session->tryout ? self::maxScoreForTryout($session->tryout) : 0.0;
    }

    /**
     * Skor maksimum satu tryout: penyebut yang sama untuk seluruh pesertanya.
     *
     * Sebelumnya isi metode ini berada di maxScoreForSession dan mengambil soal
     * satu kueri per subtest. Karena papan peringkat memanggilnya sekali per
     * sesi, satu tryout tujuh subtest dengan seribu peserta berarti ribuan kueri
     * yang seluruhnya menghasilkan angka yang sama. Sekarang seluruh soal
     * diambil dalam satu kueri, dan pemanggilnya cukup sekali.
     */
    public static function maxScoreForTryout(Tryout $tryout): float
    {
        $subtestIds = $tryout->tryoutSubtests()
            ->where('is_active', true)
            ->pluck('subtest_id');

        if ($subtestIds->isEmpty()) {
            return 0.0;
        }

        $subtests = Subtest::whereIn('id', $subtestIds)->get()->keyBy('id');

        $questionsBySubtest = Question::whereIn('subtest_id', $subtestIds)
            ->where('is_active', true)
            ->get()
            ->groupBy('subtest_id');

        $total = 0.0;

        foreach ($questionsBySubtest as $subtestId => $questions) {
            $subtest = $subtests->get($subtestId);

            if (! $subtest) {
                continue;
            }

            foreach ($questions as $question) {
                $total += self::maxScoreForQuestion($question, $subtest);
            }
        }

        return $total;
    }

    /**
     * Agregat jawaban per sesi, dalam satu kueri untuk banyak sesi sekaligus.
     *
     * Papan peringkat sebelumnya memuat seluruh jawaban tiap sesi sebagai model
     * Eloquent hanya untuk menghitungnya - enam puluh peserta dengan sembilan
     * puluh soal berarti 5.400 model dihidrasi, dan hidrasi itulah yang memakan
     * waktu, bukan kuerinya. Angka yang dibutuhkan bisa dihitung database.
     *
     * @param  iterable<string>  $sessionIds
     * @return array<string, array{answered: int, correct: int, wrong: int, raw_score: float}>
     */
    public static function sessionAggregates(iterable $sessionIds): array
    {
        $ids = collect($sessionIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return UserAnswer::query()
            ->whereIn('tryout_session_id', $ids)
            ->selectRaw('tryout_session_id as sid')
            ->selectRaw('SUM(CASE WHEN answer IS NOT NULL THEN 1 ELSE 0 END) as answered')
            ->selectRaw('SUM(CASE WHEN is_correct = 1 THEN 1 ELSE 0 END) as correct')
            ->selectRaw('SUM(CASE WHEN is_correct = 0 THEN 1 ELSE 0 END) as wrong')
            ->selectRaw('COALESCE(SUM(score), 0) as raw_score')
            ->groupBy('tryout_session_id')
            ->get()
            ->mapWithKeys(fn ($row) => [(string) $row->sid => [
                'answered' => (int) $row->answered,
                'correct' => (int) $row->correct,
                'wrong' => (int) $row->wrong,
                'raw_score' => (float) $row->raw_score,
            ]])
            ->all();
    }

    /**
     * Skor mentah IRT per sesi: jumlah bobot soal yang dijawab benar.
     *
     * Hanya pasangan (sesi, soal) untuk jawaban benar yang diambil, dan sebagai
     * baris mentah tanpa hidrasi model - yang dibutuhkan cuma dua kolom.
     *
     * @param  iterable<string>  $sessionIds
     * @param  array<string, float>  $weights
     * @return array<string, float>
     */
    public static function irtRawScores(iterable $sessionIds, array $weights): array
    {
        $ids = collect($sessionIds)->filter()->unique()->values();

        if ($ids->isEmpty() || $weights === []) {
            return [];
        }

        $rows = UserAnswer::query()
            ->whereIn('tryout_session_id', $ids)
            ->where('is_correct', true)
            ->toBase()
            ->get(['tryout_session_id', 'question_id']);

        $scores = [];

        foreach ($rows as $row) {
            $sid = (string) $row->tryout_session_id;
            $scores[$sid] = ($scores[$sid] ?? 0.0) + ($weights[(string) $row->question_id] ?? 0.0);
        }

        return $scores;
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

        // Seluruh soal dalam satu kueri, dikelompokkan di PHP. Sebelumnya satu
        // kueri per subtest, dan satu tryout bisa punya tujuh subtest - biaya
        // yang muncul di setiap pembukaan halaman hasil.
        $questionsBySubtest = Question::whereIn('subtest_id', $subtestIds)
            ->where('is_active', true)
            ->get()
            ->groupBy('subtest_id');

        foreach (Subtest::whereIn('id', $subtestIds)->get() as $subtest) {
            $questions = $questionsBySubtest->get($subtest->id) ?? collect();

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
        $scheme = self::schemeFor($subtest);

        if ($scheme === self::SCHEME_OPTION_WEIGHT) {
            return (float) QuestionOption::where('question_id', $question->id)->max('score');
        }

        // Sejalan dengan scoreAnswer: pada IRT satu soal bernilai 1, bukan
        // score_correct yang memang tidak dipakai di skema ini.
        if ($scheme === self::SCHEME_IRT) {
            return 1.0;
        }

        return (float) ($subtest->score_correct ?? 1);
    }

    /**
     * Apakah sekumpulan bobot opsi sah untuk skema option_weight.
     *
     * Aturannya mengikuti TKP SKD: setiap opsi punya bobot bilangan bulat 1-5,
     * tidak boleh ada yang kembar, dan harus ada satu opsi bernilai 5 - yaitu
     * respons paling ideal. Untuk soal berisi 5 opsi, ketiga syarat itu hanya
     * bisa dipenuhi oleh permutasi 1,2,3,4,5.
     *
     * Dipakai baik oleh form soal maupun impor Excel supaya keduanya tidak
     * mungkin punya definisi "bobot benar" yang berbeda.
     */
    public static function validateOptionWeights(array $scores): ?string
    {
        if (count($scores) < 2) {
            return 'Soal dengan bobot per opsi butuh minimal 2 opsi.';
        }

        $values = [];

        foreach ($scores as $score) {
            if ($score === null || $score === '') {
                return 'Setiap opsi wajib punya bobot 1-5. Pada skema ini tidak ada opsi yang bernilai 0.';
            }

            $value = (float) $score;

            if (floor($value) != $value
                || $value < self::OPTION_WEIGHT_MIN
                || $value > self::OPTION_WEIGHT_MAX) {
                return 'Bobot opsi harus bilangan bulat ' . self::OPTION_WEIGHT_MIN . '-' . self::OPTION_WEIGHT_MAX . '.';
            }

            $values[] = (int) $value;
        }

        if (count(array_unique($values)) !== count($values)) {
            return 'Bobot antar opsi tidak boleh kembar: urutkan dari 1 (paling tidak sesuai) sampai 5 (paling sesuai).';
        }

        if (! in_array(self::OPTION_WEIGHT_MAX, $values, true)) {
            return 'Harus ada satu opsi bernilai ' . self::OPTION_WEIGHT_MAX . ' sebagai jawaban paling ideal.';
        }

        return null;
    }

    /**
     * Soal option_weight yang bobotnya belum digarap.
     *
     * Soal yang diimpor sebelum kolom bobot ada tersimpan sebagai 1 untuk kunci
     * dan 0 untuk sisanya. Itu tetap menghasilkan angka, jadi tanpa penanda
     * eksplisit soal rusak seperti ini tidak terlihat di mana pun - hanya
     * membuat skor TKP peserta jauh lebih rendah dari seharusnya.
     */
    public static function questionNeedsOptionWeights(Question $question, Subtest $subtest): bool
    {
        if (self::schemeFor($subtest) !== self::SCHEME_OPTION_WEIGHT) {
            return false;
        }

        $scores = QuestionOption::where('question_id', $question->id)
            ->orderBy('option_key')
            ->pluck('score')
            ->all();

        return self::validateOptionWeights($scores) !== null;
    }

    /**
     * Bobot IRT setiap soal dalam satu tryout, dihitung sekali.
     *
     * Bobotnya diturunkan dari proporsi peserta yang menjawab benar: makin
     * sedikit yang benar, makin berat soalnya. Karena itu nilainya **sifat
     * tryout, bukan sifat peserta** - dua peserta yang berbeda menghasilkan
     * bobot yang sama persis.
     *
     * Sebelumnya perhitungan ini ada dua kali - di halaman hasil dan di papan
     * peringkat - dan masing-masing melakukan satu kueri COUNT per soal di dalam
     * loop. Untuk tryout 90 soal itu 90 kueri, setiap kali salah satu halaman
     * dibuka, oleh setiap peserta. Sekarang jumlah benar per soal diambil dalam
     * satu kueri teragregasi, jadi biayanya tidak lagi tumbuh mengikuti jumlah
     * soal.
     *
     * Hasilnya di-cache dengan kunci yang memuat jumlah sesi selesai, sehingga
     * batal dengan sendirinya begitu ada peserta baru menyelesaikan tryout -
     * tidak ada langkah pembatalan cache yang bisa terlupa. Efek sampingnya
     * justru diinginkan: selama satu jendela cache, halaman hasil dan papan
     * peringkat pasti memakai bobot yang identik.
     *
     * Menerima array maupun Collection karena kedua pemanggilnya menyusun daftar
     * subtes dengan cara berbeda, dan memaksa salah satunya berubah bentuk hanya
     * memindahkan konversinya ke tempat yang lebih mudah terlupa.
     *
     * @param  iterable<int|string>  $subtestIds
     * @return array{weights: array<string, float>, total: float, participants: int}
     */
    public static function irtWeights(Tryout $tryout, iterable $subtestIds): array
    {
        $ids = collect($subtestIds)->filter()->unique()->sort()->values()->all();

        $participants = TryoutSession::where('tryout_id', $tryout->id)
            ->where('status', 'finished')
            ->count();

        if ($participants < 1 || $ids === []) {
            return ['weights' => [], 'total' => 0.0, 'participants' => $participants];
        }

        $key = sprintf(
            'irt-weights:%s:%d:%s',
            $tryout->id,
            $participants,
            md5(implode(',', $ids)),
        );

        // TTL pendek sebagai jaring pengaman: jumlah sesi selesai menangkap
        // hampir semua perubahan, tetapi tidak menangkap koreksi jawaban yang
        // dilakukan tanpa menambah sesi. Lima menit membatasi seberapa lama
        // bobot basi bisa terpakai.
        return Cache::remember($key, now()->addMinutes(5), function () use ($tryout, $ids, $participants) {
            $questionIds = Question::whereIn('subtest_id', $ids)
                ->where('is_active', true)
                ->pluck('id');

            if ($questionIds->isEmpty()) {
                return ['weights' => [], 'total' => 0.0, 'participants' => $participants];
            }

            // Satu kueri untuk seluruh soal, menggantikan satu kueri per soal.
            // Join dipakai alih-alih whereHas karena whereHas menghasilkan
            // subquery berkorelasi yang dievaluasi ulang per baris.
            $correctCounts = UserAnswer::query()
                ->join('tryout_sessions', 'tryout_sessions.id', '=', 'user_answers.tryout_session_id')
                ->where('tryout_sessions.tryout_id', $tryout->id)
                ->where('tryout_sessions.status', 'finished')
                ->where('user_answers.is_correct', true)
                ->whereIn('user_answers.question_id', $questionIds)
                ->groupBy('user_answers.question_id')
                ->pluck(DB::raw('count(*)'), 'user_answers.question_id');

            $weights = [];
            $total = 0.0;

            foreach ($questionIds as $questionId) {
                // Soal yang tidak dijawab benar oleh siapa pun tidak muncul di
                // hasil agregasi, dan justru itu soal terberatnya - jadi
                // bawaannya nol, bukan dilewati.
                $p = ((int) ($correctCounts[$questionId] ?? 0)) / $participants;
                $safeP = $p <= 0 ? 0.0001 : ($p >= 1 ? 0.9999 : $p);
                $weight = max(1, log((1 - $safeP) / $safeP) + 2);

                $weights[(string) $questionId] = $weight;
                $total += $weight;
            }

            return ['weights' => $weights, 'total' => $total, 'participants' => $participants];
        });
    }

    public static function forgetIrtWeights(Tryout $tryout): void
    {
        $ids = $tryout->tryoutSubtests()
            ->where('is_active', true)
            ->pluck('subtest_id')
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        $participants = TryoutSession::where('tryout_id', $tryout->id)
            ->where('status', 'finished')
            ->count();

        if ($participants < 1 || $ids === []) {
            return;
        }

        Cache::forget(sprintf(
            'irt-weights:%s:%d:%s',
            $tryout->id,
            $participants,
            md5(implode(',', $ids)),
        ));
    }
}
