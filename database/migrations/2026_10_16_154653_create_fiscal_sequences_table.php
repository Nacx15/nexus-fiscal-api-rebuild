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
        Schema::create('fiscal_sequences', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('document_type', 30);

            $table->string('series', 20);

            $table->unsignedBigInteger('current_number')
                ->default(0);

            $table->unsignedTinyInteger('padding')
                ->default(6);

            $table->boolean('active')
                ->default(true);

            $table->timestamps();

            $table->unique([
                'company_id',
                'document_type',
                'series',
            ]);

            $table->index([
                'company_id',
                'active',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_sequences');
    }
};
