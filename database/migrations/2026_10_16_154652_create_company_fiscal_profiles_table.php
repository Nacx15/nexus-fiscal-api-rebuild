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
        Schema::create('company_fiscal_profiles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('business_name');
            $table->string('rfc', 13);

            $table->string('tax_regime', 3);
            $table->string('postal_code', 5);

            $table->string('email')->nullable();
            $table->string('logo_path')->nullable();

            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique('company_id');
            $table->index('rfc');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('company_fiscal_profiles');
    }
};
