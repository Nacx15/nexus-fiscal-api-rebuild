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
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            $table->ulid('public_id')->unique();

            $table->foreignId('company_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('client_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            /*
            * Se asignarán al finalizar el draft,
            * no al crearlo.
            */
            $table->foreignId('sequence_id')
                ->nullable()
                ->constrained('fiscal_sequences')
                ->restrictOnDelete();

            $table->string('series', 20)->nullable();

            $table->unsignedBigInteger('folio')
                ->nullable();

            $table->string('status', 30)
                ->default('draft');

            /*
            * Snapshot fiscal del receptor.
            */
            $table->string('receiver_rfc', 13);

            $table->string(
                'receiver_name',
                255
            );

            $table->string(
                'receiver_tax_regime',
                10
            );

            $table->string(
                'receiver_postal_code',
                5
            );

            /*
            * Datos CFDI.
            */
            $table->string('payment_form', 10);

            $table->string('payment_method', 10);

            $table->string('cfdi_use', 10);

            /*
            * Por ahora trabajamos con MXN.
            */
            $table->char('currency', 3)
                ->default('MXN');

            $table->decimal(
                'exchange_rate',
                18,
                6
            )->default(1);

            /*
            * Mantenemos precisión interna
            * de seis decimales.
            */
            $table->decimal(
                'subtotal',
                18,
                6
            )->default(0);

            $table->decimal(
                'discount',
                18,
                6
            )->default(0);

            $table->decimal(
                'transferred_taxes',
                18,
                6
            )->default(0);

            $table->decimal(
                'withheld_taxes',
                18,
                6
            )->default(0);

            $table->decimal(
                'total',
                18,
                6
            )->default(0);

            $table->dateTime('issued_at')
                ->nullable();

            $table->text('notes')
                ->nullable();

            $table->timestamps();

            $table->unique([
                'company_id',
                'series',
                'folio',
            ]);

            $table->index([
                'company_id',
                'status',
            ]);

            $table->index([
                'company_id',
                'client_id',
            ]);

            $table->index([
                'company_id',
                'issued_at',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
