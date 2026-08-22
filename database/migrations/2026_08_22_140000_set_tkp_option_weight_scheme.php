<?php

use App\Models\Subtest;
use App\Services\ScoringService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TKP (CPNS) is scored per-option on a 1-5 scale, not right/wrong: every option
 * carries partial credit and none is simply "wrong". Existing rows were seeded
 * as right_wrong with score 0 on every non-key option, which made the whole
 * subtest worth almost nothing.
 *
 * Flips TKP subtests to the option_weight scheme. Option weights themselves are
 * content decisions and are seeded per question (see CpnsQuestionSeeder); this
 * only gives previously-zeroed options a non-zero floor so no answer scores 0.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tkp = Subtest::where('exam_type', 'cpns')
            ->where('name', 'like', '%TKP%')
            ->get();

        foreach ($tkp as $subtest) {
            $subtest->update([
                'scoring_scheme' => ScoringService::SCHEME_OPTION_WEIGHT,
            ]);

            // Options left at 0 by the right_wrong backfill would score nothing.
            // Give them the TKP floor of 1; real weights come from the seeder.
            DB::table('question_options')
                ->whereIn('question_id', function ($query) use ($subtest) {
                    $query->select('id')
                        ->from('questions')
                        ->where('subtest_id', $subtest->id);
                })
                ->where('score', '<=', 0)
                ->update(['score' => 1]);
        }
    }

    public function down(): void
    {
        Subtest::where('exam_type', 'cpns')
            ->where('name', 'like', '%TKP%')
            ->update(['scoring_scheme' => ScoringService::SCHEME_RIGHT_WRONG]);
    }
};
