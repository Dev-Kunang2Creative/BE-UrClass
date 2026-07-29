<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kelas_orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('order_code')->unique();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUlid('kelas_id')->constrained('kelas')->cascadeOnDelete();
            $table->unsignedBigInteger('grand_total');
            $table->string('currency', 10)->default('IDR');
            $table->string('status')->default('pending'); // pending, paid, cancelled
            $table->string('midtrans_transaction_id')->nullable();
            $table->string('payment_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kelas_orders');
    }
};
