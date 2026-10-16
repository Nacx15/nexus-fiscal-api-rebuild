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
        Schema::create('companies', function (Blueprint $table) {
            $table->id();

            $table->ulid('public_id')->unique();

            $table->string('legal_name');
            $table->string('trade_name')->nullable();

            $table->string('rfc', 13)->unique();

            $table->string('status', 20)
                ->default('active');

            $table->string('timezone', 64)
                ->default('America/Mexico_City');

            $table->char('currency', 3)
                ->default('MXN');

            $table->timestamps();

            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
