<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained();
            $table->string('name', 100);
            $table->string('type', 20);
            $table->bigInteger('credit_limit')->nullable(); // centavos (só crédito)
            $table->unsignedTinyInteger('closing_day')->nullable(); // só crédito
            $table->unsignedTinyInteger('due_day')->nullable(); // só crédito
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cards');
    }
};
