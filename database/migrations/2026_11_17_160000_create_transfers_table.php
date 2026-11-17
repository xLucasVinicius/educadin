<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_account_id')->constrained('accounts')->cascadeOnDelete();
            $table->foreignId('to_account_id')->constrained('accounts')->cascadeOnDelete();
            $table->string('description', 150)->nullable();
            $table->bigInteger('amount'); // centavos, sempre positivo
            $table->date('date');
            $table->timestamps();

            $table->index(['from_account_id', 'date']);
            $table->index(['to_account_id', 'date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};
