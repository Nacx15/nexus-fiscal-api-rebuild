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
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_id')
                ->constrained()
                ->cascadeOnDelete();

            /*
            * Referencia al producto actual.
            * El snapshot de abajo mantiene
            * la información histórica.
            */
            $table->foreignId('product_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();

            $table->unsignedInteger('position');

            /*
            * Snapshot del producto.
            */
            $table->string('product_code', 8);

            $table->string('unit_code', 10);

            $table->string('unit_name', 100)
                ->nullable();

            $table->text('description');

            $table->decimal(
                'quantity',
                18,
                6
            );

            $table->decimal(
                'unit_price',
                18,
                6
            );

            $table->decimal(
                'discount',
                18,
                6
            )->default(0);

            /*
            * quantity * unit_price,
            * antes del descuento.
            */
            $table->decimal(
                'subtotal',
                18,
                6
            );

            $table->string('tax_object', 2);

            /*
            * subtotal - discount
            * + traslados - retenciones.
            */
            $table->decimal(
                'total',
                18,
                6
            );

            $table->timestamps();

            $table->unique([
                'invoice_id',
                'position',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};
