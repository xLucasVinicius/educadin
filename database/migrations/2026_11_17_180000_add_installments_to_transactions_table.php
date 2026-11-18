<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            // Compra parcelada: cada parcela é um lançamento; o grupo liga as parcelas.
            $table->string('installment_group', 36)->nullable()->index();
            $table->unsignedTinyInteger('installment_number')->nullable();
            $table->unsignedTinyInteger('installment_total')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropIndex(['installment_group']);
            $table->dropColumn(['installment_group', 'installment_number', 'installment_total']);
        });
    }
};
