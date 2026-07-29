<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->string('order_code')->unique();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();

            $table->unsignedBigInteger('grand_total');
            $table->string('currency', 10)->default('IDR');

            $table->enum('status', [
                'pending',
                'waiting_approval',
                'paid',
                'rejected',
                'expired',
                'cancelled'
            ])->default('pending');

            $table->enum('payment_method', [
                'manual_transfer',
                'midtrans'
            ])->default('manual_transfer');

            $table->string('payment_reference')->nullable(); // buat Midtrans nanti
            $table->string('midtrans_transaction_id')->nullable();
            $table->string('midtrans_order_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignUlid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('admin_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};