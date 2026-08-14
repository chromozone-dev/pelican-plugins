<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            // Address the proxy manager should dial to reach this node. Needed when
            // allocations bind 0.0.0.0, or when the proxy reaches the node on a
            // different address than the node's public FQDN.
            $table->string('proxy_forward_host')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('proxy_forward_host');
        });
    }
};
