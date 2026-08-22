<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Content is split along the same axis as users.kategori (utbk|cpns).
     * Existing rows predate CPNS, so they default to utbk.
     */
    private array $tables = ['tryouts', 'packages', 'kelas'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || Schema::hasColumn($table, 'kategori')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->enum('kategori', ['utbk', 'cpns'])->default('utbk')->index();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'kategori')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('kategori');
            });
        }
    }
};
