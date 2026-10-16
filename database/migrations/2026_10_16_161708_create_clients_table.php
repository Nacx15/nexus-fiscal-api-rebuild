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
        Schema::create('clients', function (Blueprint $table) {
            $table->id();

            $table->ulid('public_id')->unique();

            $table->foreignId('company_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('business_name');

            $table->string('rfc', 13);

            $table->string('tax_regime', 3);

            $table->string('postal_code', 5);

            $table->string('email')->nullable();

            $table->string('phone', 30)->nullable();

            $table->string('status', 20)
                ->default('active');

            $table->timestamps();

            $table->unique([
                'company_id',
                'rfc',
            ]);

            $table->index([
                'company_id',
                'status',
            ]);

            $table->index([
                'company_id',
                'business_name',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clients');
    }
};
