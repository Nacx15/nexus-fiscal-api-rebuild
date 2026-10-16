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
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            $table->ulid('public_id')->unique();

            $table->foreignId('company_id')
                ->constrained()
                ->restrictOnDelete();

            /*
            * Identificador interno de la empresa.
            */
            $table->string('sku', 100);

            /*
            * Datos SAT.
            */
            $table->string('sat_product_code', 8);

            $table->string('sat_unit_code', 10);

            $table->string('unit_name', 100)->nullable();

            /*
            * Información comercial.
            */
            $table->text('description');

            $table->decimal('unit_price', 18, 6);

            /*
            * Ejemplo:
            * 0.160000
            *
            * nullable porque no todos los productos
            * necesariamente tendrán una tasa por defecto.
            */
            $table->decimal(
                'default_tax_rate',
                12,
                6
            )->nullable();

            /*
            * Código SAT de ObjetoImp.
            */
            $table->string('tax_object', 2);

            $table->string('status', 20)
                ->default('active');

            $table->timestamps();

            /*
            * El SKU solamente necesita ser único
            * dentro de la empresa.
            */
            $table->unique([
                'company_id',
                'sku',
            ]);

            $table->index([
                'company_id',
                'status',
            ]);

            $table->index([
                'company_id',
                'sat_product_code',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
