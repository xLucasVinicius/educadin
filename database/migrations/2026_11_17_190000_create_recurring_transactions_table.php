<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recurring_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Como nos lançamentos: pertence a uma conta OU a um cartão.
            $table->foreignId('account_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('card_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('description', 150);
            $table->bigInteger('amount'); // centavos, sempre positivo
            $table->string('category', 30);
            $table->unsignedTinyInteger('day_of_month'); // 1 a 31 (meses curtos usam o último dia)
            $table->date('next_due_on'); // próxima data ainda não lançada
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'cancelled_at', 'next_due_on']);
        });

        Schema::table('transactions', function (Blueprint $table) {
            $table->foreignId('recurring_transaction_id')->nullable()->constrained()->nullOnDelete();
            // Garante que a mesma ocorrência nunca seja lançada duas vezes.
            $table->unique(['recurring_transaction_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropUnique(['recurring_transaction_id', 'date']);
            $table->dropConstrainedForeignId('recurring_transaction_id');
        });

        Schema::dropIfExists('recurring_transactions');
    }
};
