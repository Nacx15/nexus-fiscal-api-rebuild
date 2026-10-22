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
        Schema::create('fiscal_stamp_audit_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('fiscal_document_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('fiscal_stamp_attempt_id')
                ->constrained('fiscal_stamp_attempts')
                ->restrictOnDelete();

            $table->string('event_type', 40);

            $table->string(
                'previous_status',
                30
            )->nullable();

            $table->string('current_status', 30);

            /*
             * Integridad del XML firmado.
             * No almacenamos el contenido XML.
             */
            $table->char(
                'xml_sha256',
                64
            )->nullable();

            /*
             * Sólo se registra después de
             * confirmar el timbrado.
             */
            $table->char(
                'uuid',
                36
            )->nullable();

            $table->timestamp(
                'occurred_at',
                6
            )->useCurrent();

            /*
             * Una transición de cada tipo
             * por intento fiscal.
             */
            $table->unique(
                [
                    'fiscal_stamp_attempt_id',
                    'event_type',
                ],
                'stamp_audit_event_once'
            );

            $table->index([
                'company_id',
                'occurred_at',
            ]);

            $table->index([
                'fiscal_document_id',
                'occurred_at',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_stamp_audit_events');
    }
};
