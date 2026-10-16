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
        Schema::create('fiscal_certificates', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('certificate_number', 30);

            $table->string('cer_path');
            $table->string('key_path');

            $table->text('key_password')->nullable();

            $table->dateTime('valid_from');
            $table->dateTime('valid_to');

            $table->string('status', 20)
                ->default('active');

            $table->timestamps();

            $table->unique([
                'company_id',
                'certificate_number',
            ]);

            $table->index([
                'company_id',
                'status',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_certificates');
    }
};
