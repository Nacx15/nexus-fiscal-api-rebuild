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
        Schema::create('fiscal_document_files', function (Blueprint $table) {
            $table->id();

            $table->foreignId('fiscal_document_id')
                ->constrained('fiscal_documents')
                ->restrictOnDelete();

            $table->string('file_type', 30);

            $table->string('disk', 50);
            $table->string('path', 500);

            $table->string('mime_type', 100);

            $table->unsignedBigInteger('size');

            $table->char('checksum', 64);

            $table->timestamps();

            $table->unique(
                ['fiscal_document_id', 'file_type'],
                'fiscal_file_type_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_files');
    }
};
