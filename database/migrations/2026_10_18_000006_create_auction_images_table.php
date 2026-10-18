<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime', 50);
            $table->unsignedInteger('size_bytes');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['auction_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_images');
    }
};
