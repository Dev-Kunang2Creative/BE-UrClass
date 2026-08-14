<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_tryout_access', function (Blueprint $table) {
            $table->enum('selection_status', [
                'submitted', 'under_review', 'need_revision', 'accepted', 'rejected',
            ])->nullable()->after('proof_images');
            $table->text('selection_note')->nullable()->after('selection_status');
            $table->timestamp('selection_reviewed_at')->nullable()->after('selection_note');
            $table->unsignedBigInteger('selection_reviewed_by')->nullable()->after('selection_reviewed_at');

            $table->index('selection_status');
        });
    }

    public function down(): void
    {
        Schema::table('user_tryout_access', function (Blueprint $table) {
            $table->dropIndex(['selection_status']);
            $table->dropColumn([
                'selection_status', 'selection_note',
                'selection_reviewed_at', 'selection_reviewed_by',
            ]);
        });
    }
};
