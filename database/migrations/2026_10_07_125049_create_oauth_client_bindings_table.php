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
        Schema::create('oauth_client_bindings', function (Blueprint $table) {
            $table->uuid('client_id')->primary();         // = oauth_clients.id
            $table->string('platform', 20);               // 'web' atau 'mobile'
            $table->string('allowed_origin')->nullable(); // domain web
            $table->string('bound_device_id')->nullable(); // terisi otomatis untuk mobile
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('oauth_client_bindings');
    }
};
