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
        Schema::create('fiscal_stamp_attempts', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('company_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('fiscal_document_id')
                ->constrained()
                ->restrictOnDelete();

            $table->unsignedInteger('attempt_number');

            $table->string('status', 30)
                ->default('preparing');

            /*
            * Fecha local exacta escrita en el XML.
            * Nunca debe recalcularse al reintentar.
            */
            $table->char('cfdi_date', 19);
            $table->string('cfdi_timezone', 64);

            /*
            * XML firmado, guardado privadamente
            * antes de cualquier solicitud al PAC.
            */
            $table->string('xml_disk', 50)->nullable();
            $table->string('xml_path', 500)->nullable();
            $table->char('xml_sha256', 64)->nullable();
            $table->unsignedBigInteger('xml_size')->nullable();
            $table->dateTime('preflight_passed_at')->nullable();

            /*
            * Datos del proveedor.
            */
            $table->string('provider', 50)
                ->default('sw_sapien');

            $table->string('provider_reference', 150)
                ->nullable();

            $table->string('failure_code', 100)
                ->nullable();

            $table->text('failure_message')->nullable();

            $table->dateTime('dispatch_started_at')
                ->nullable();

            $table->dateTime('completed_at')
                ->nullable();

            $table->timestamps();

            $table->unique(
                ['fiscal_document_id', 'attempt_number'],
                'stamp_attempt_number_unique'
            );

            $table->index(['company_id', 'status']);
            $table->index(['fiscal_document_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_stamp_attempts');
    }
};
