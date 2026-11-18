<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
            $table->timestamp('suspended_at')->nullable();
            // Plano do usuário: "free" ou "premium" (válido até premium_until; vazio = sem vencimento).
            $table->string('plan', 20)->default('free');
            $table->date('premium_until')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'suspended_at', 'plan', 'premium_until']);
        });
    }
};
