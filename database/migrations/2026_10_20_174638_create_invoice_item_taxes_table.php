<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('invoice_item_taxes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_item_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
            * SAT:
            * 001 ISR
            * 002 IVA
            * 003 IEPS
            */
            $table->string('tax', 3);

            /*
            * transferred / withheld
            */
            $table->string('type', 20);

            /*
            * Tasa / Cuota / Exento
            */
            $table->string('factor_type', 20);

            $table->decimal(
                'rate_or_quota',
                18,
                6
            )->nullable();

            $table->decimal(
                'base',
                18,
                6
            );

            $table->decimal(
                'amount',
                18,
                6
            )->nullable();

            $table->timestamps();

            $table->index('invoice_item_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_item_taxes');
    }
};
