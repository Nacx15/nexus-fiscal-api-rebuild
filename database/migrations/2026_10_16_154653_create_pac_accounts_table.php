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
        Schema::create('pac_accounts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('provider', 50);

            $table->string('environment', 20);

            $table->text('username')->nullable();
            $table->text('password')->nullable();
            $table->text('token')->nullable();

            $table->boolean('active')->default(true);

            $table->timestamps();

            $table->unique([
                'company_id',
                'provider',
                'environment',
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
        Schema::dropIfExists('pac_accounts');
    }
};
