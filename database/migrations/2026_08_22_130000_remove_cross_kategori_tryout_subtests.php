<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TryoutSeeder used to attach every subtest to every tryout, so UTBK tryouts
 * ended up carrying CPNS subtests (TWK/TIU/TKP) and vice versa. Drop the rows
 * whose subtest belongs to a different exam track than its tryout.
 *
 * Rows that already have exam sessions attached are left alone: deleting them
 * would orphan a student's answers. Those need a manual call.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mismatched = DB::table('tryout_subtests as ts')
            ->join('tryouts as t', 't.id', '=', 'ts.tryout_id')
            ->join('subtests as s', 's.id', '=', 'ts.subtest_id')
            ->whereColumn('t.kategori', '!=', 's.exam_type')
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('tryout_subtest_sessions as sess')
                    ->whereColumn('sess.tryout_subtest_id', 'ts.id');
            })
            ->pluck('ts.id');

        if ($mismatched->isNotEmpty()) {
            DB::table('tryout_subtests')->whereIn('id', $mismatched)->delete();
        }
    }

    public function down(): void
    {
        // Re-attaching wrong-track subtests would restore the bug; nothing to undo.
    }
};
