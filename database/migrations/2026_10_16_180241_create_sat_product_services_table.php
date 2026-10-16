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
        Schema::create('sat_product_services', function (Blueprint $table) {
            $table->id();

            $table->string('code', 8)->unique();

            $table->text('description');

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
        Schema::dropIfExists('sat_product_services');
    }
};
