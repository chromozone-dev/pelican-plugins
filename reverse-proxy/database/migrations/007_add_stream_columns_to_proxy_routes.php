<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proxy_routes', function (Blueprint $table) {
            // Which published port on the proxy manager this route occupies. Null
            // for http routes, which all share 80/443 via the Host header.
            $table->unsignedInteger('stream_port_id')->nullable();
            $table->foreign('stream_port_id')->references('id')->on('proxy_stream_ports')->nullOnDelete();

            // Which protocols to forward. A single stream carries both in the
            // proxy manager's model, so these are flags rather than rows.
            $table->boolean('stream_tcp')->default(true);
            $table->boolean('stream_udp')->default(false);

            // One route per port: nginx stream cannot tell two hostnames apart on
            // the same port, so a second claim would silently shadow the first.
            $table->unique('stream_port_id');
        });
    }

    public function down(): void
    {
        Schema::table('proxy_routes', function (Blueprint $table) {
            $table->dropUnique(['stream_port_id']);
            $table->dropForeign(['stream_port_id']);
            $table->dropColumn(['stream_port_id', 'stream_tcp', 'stream_udp']);
        });
    }
};
