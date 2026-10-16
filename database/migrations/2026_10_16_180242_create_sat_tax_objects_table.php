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
        Schema::create('sat_tax_objects', function (Blueprint $table) {
            $table->id();

            $table->string('code', 2)->unique();

            $table->string('description');

            $table->date('valid_from')->nullable();
            $table->date('valid_to')->nullable();

            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->index('active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sat_tax_objects');
    }
};
