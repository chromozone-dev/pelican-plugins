<?php

use Chromozone\ReverseProxy\Models\ProxyRoute;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proxy_routes', function (Blueprint $table) {
            $table->increments('id');

            $table->unsignedInteger('server_id');
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();

            $table->unsignedInteger('allocation_id');
            $table->foreign('allocation_id')->references('id')->on('allocations')->cascadeOnDelete();

            $table->unsignedInteger('domain_id');
            $table->foreign('domain_id')->references('id')->on('proxy_domains')->cascadeOnDelete();

            $table->string('label');
            $table->string('type')->default('http');
            $table->string('forward_scheme')->default('http');
            // Proxy host id in the proxy manager. Null until the first successful sync.
            $table->string('external_id')->nullable();
            $table->boolean('websockets')->default(true);
            $table->boolean('block_exploits')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['label', 'domain_id']);
        });
    }

    public function down(): void
    {
        // Deleting through the model also removes each entry from the proxy
        // manager, so it is preferred - but it must never be required.
        //
        // Plugin namespaces are registered at boot from the list of installed
        // plugins, and uninstalling runs in a queued job. A worker that started
        // before this plugin was installed cannot resolve the class, and letting
        // that abort the rollback leaves the plugin impossible to uninstall.
        if (class_exists(ProxyRoute::class)) {
            ProxyRoute::all()->each(function (ProxyRoute $route) {
                try {
                    $route->delete();
                } catch (Exception) {
                }
            });
        } else {
            Log::warning('reverse-proxy: ProxyRoute could not be loaded while rolling back, so proxy entries were left in the proxy manager. Remove them by hand, or restart the queue worker and reinstall before uninstalling again.');
        }

        Schema::dropIfExists('proxy_routes');
    }
};
