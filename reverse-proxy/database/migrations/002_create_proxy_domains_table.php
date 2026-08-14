<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_domains', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('target_id');
            $table->foreign('target_id')->references('id')->on('proxy_targets')->cascadeOnDelete();

            $table->string('name')->unique();
            // Certificate in the proxy manager that covers *.<name>, chosen by an admin.
            $table->unsignedInteger('certificate_id')->nullable();
            $table->boolean('force_ssl')->default(true);
            $table->boolean('allow_user_routes')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_domains');
    }
};
