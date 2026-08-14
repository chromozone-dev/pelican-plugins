<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_stream_ports', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('target_id');
            $table->foreign('target_id')->references('id')->on('proxy_targets')->cascadeOnDelete();

            // Must already be published on the proxy manager's container: Docker
            // cannot add ports to a running container, so the plugin allocates
            // from what an admin declares here rather than pretending otherwise.
            $table->unsignedInteger('port');
            $table->boolean('tcp')->default(true);
            $table->boolean('udp')->default(false);
            $table->string('label')->nullable();

            $table->timestamps();

            $table->unique(['target_id', 'port']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proxy_stream_ports');
    }
};
