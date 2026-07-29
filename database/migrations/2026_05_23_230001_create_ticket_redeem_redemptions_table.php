<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ticket_redeem_redemptions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('ticket_redeem_code_id')->constrained('ticket_redeem_codes')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('ticket_amount');
            $table->timestamp('redeemed_at');
            $table->timestamps();

            $table->unique(['ticket_redeem_code_id', 'user_id'], 'ticket_redeem_user_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_redeem_redemptions');
    }
};
