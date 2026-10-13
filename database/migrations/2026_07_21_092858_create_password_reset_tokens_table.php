<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('token', 100)->unique();
            $table->string('fingerprint');
            $table->string('cookie_session_id')->nullable(); 
            $table->timestamp('expires_at');
            $table->boolean('is_used')->default(true);
            $table->timestamps();       
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};