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
        Schema::create('fiscal_documents', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();

            $table->foreignId('company_id')
                ->constrained()
                ->restrictOnDelete();

            /*
            * Relación polimórfica:
            * Invoice o, posteriormente, CreditNote.
            */
            $table->morphs('documentable');

            $table->string('cfdi_type', 5);
            $table->string('version', 10)->default('4.0');

            /*
            * UUID asignado después de timbrar.
            */
            $table->uuid('uuid')->nullable()->unique();

            $table->string('status', 30)->default('pending');

            /*
            * Snapshot fiscal del emisor.
            */
            $table->string('issuer_rfc', 13);
            $table->string('issuer_name', 255);
            $table->string('issuer_tax_regime', 10);
            $table->string('issuer_postal_code', 5);

            /*
            * Snapshot fiscal del receptor.
            */
            $table->string('receiver_rfc', 13);
            $table->string('receiver_name', 255);
            $table->string('receiver_tax_regime', 10);
            $table->string('receiver_postal_code', 5);

            /*
            * Importes y moneda.
            */
            $table->char('currency', 3)->default('MXN');

            $table->decimal('exchange_rate', 18, 6)
                ->default(1);

            $table->decimal('subtotal', 18, 6);
            $table->decimal('discount', 18, 6)->default(0);

            $table->decimal('transferred_taxes', 18, 6)
                ->default(0);

            $table->decimal('withheld_taxes', 18, 6)
                ->default(0);

            $table->decimal('total', 18, 6);

            /*
            * Información del timbrado futuro.
            */
            $table->dateTime('stamped_at')->nullable();

            $table->string('certificate_number', 30)
                ->nullable();

            $table->string('sat_certificate_number', 30)
                ->nullable();

            $table->longText('cfdi_seal')->nullable();
            $table->longText('sat_seal')->nullable();

            $table->longText('original_string')->nullable();

            $table->timestamps();

            $table->unique(
                ['documentable_type', 'documentable_id'],
                'fiscal_document_owner_unique'
            );

            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'stamped_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_documents');
    }
};
