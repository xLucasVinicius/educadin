<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            // Despesa gerada na conta; se ela for apagada, o pagamento some junto.
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->string('month', 7); // fatura que fecha neste mês, ex.: "2026-11"
            $table->bigInteger('amount'); // centavos
            $table->date('paid_at');
            $table->timestamps();

            // Uma fatura pode ter mais de um pagamento (compras feitas depois de pagar).
            $table->index(['card_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_payments');
    }
};
