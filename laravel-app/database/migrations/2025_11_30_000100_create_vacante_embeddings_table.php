<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vacante_embeddings', function (Blueprint $table) {
            $table->unsignedBigInteger('vacante_id')->primary();
            $table->json('embedding');
            $table->double('norm');
            $table->timestamps();

            $table->foreign('vacante_id')
                ->references('id')
                ->on('vacantes')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vacante_embeddings');
    }
};
