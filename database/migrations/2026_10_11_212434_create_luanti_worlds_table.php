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
        Schema::create('luanti_worlds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('luanti_server_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('world_identifier');
            $table->boolean('is_selectable')->default(true);
            $table->unique(['luanti_server_id', 'world_identifier']);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('luanti_worlds');
    }
};
