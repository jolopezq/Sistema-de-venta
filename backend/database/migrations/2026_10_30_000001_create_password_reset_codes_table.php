<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Códigos de 6 dígitos para recuperar la contraseña por correo (docs/PLAN_RECUPERAR_CONTRASENA.md).
 * Solo se guardan huellas (HMAC con APP_KEY): ni el código ni el permiso para cambiar la contraseña quedan legibles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash', 64);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            // Tras verificar el código: permiso de un solo uso para poner la contraseña nueva
            $table->string('reset_token_hash', 64)->nullable();
            $table->timestamp('reset_expires_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'used_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_codes');
    }
};
