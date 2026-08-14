<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_targets', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->string('driver')->default('npm');
            $table->string('base_url');
            $table->string('identity');
            // Encrypted at rest via the model cast, so this needs more room than a string.
            $table->text('secret');
            $table->boolean('verify_tls')->default(true);
            // Filled in by autodetection on the first successful connection.
            $table->string('variant')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_targets');
    }
};
