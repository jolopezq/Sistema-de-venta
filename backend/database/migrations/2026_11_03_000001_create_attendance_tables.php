<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asistencia en el local (docs/PLAN_ASISTENCIA_Y_SUELDOS.md §5.1). Aditiva: solo crea tablas nuevas.
 *
 * - employee_profiles: datos laborales de una persona (cómo se le paga, horario, PIN para marcar, consentimiento).
 * - face_enrollments: rostro registrado (descriptor del navegador y foto de referencia).
 * - attendance_networks: redes del local (IPv4 exacta o rango; IPv6 por prefijo /64).
 * - attendance_devices: equipos autorizados para el reloj (se guarda la huella de la llave, nunca la llave).
 * - attendance_marks: cada entrada y salida. Nunca se borran ni se editan en su lugar: una corrección crea otra marca.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->boolean('attendance_enabled')->default(true);
            $table->string('pin_hash')->nullable();
            $table->string('pay_mode', 20)->default('hourly')->comment('hourly | monthly | per_shift');
            $table->decimal('hourly_rate', 10, 2)->nullable();
            $table->decimal('monthly_salary', 12, 2)->nullable();
            $table->decimal('shift_rate', 10, 2)->nullable();
            $table->decimal('shift_hours', 4, 2)->default(8);
            $table->json('schedule')->nullable()->comment('Por día ISO (1 = lunes … 7 = domingo): {"1": {"start": "14:00", "end": "22:00"}}');
            $table->unsignedSmallInteger('late_tolerance_min')->default(10);
            $table->decimal('overtime_after_hours', 4, 2)->default(8);
            $table->decimal('overtime_multiplier', 4, 2)->default(2);
            $table->decimal('sunday_multiplier', 4, 2)->default(1);
            $table->decimal('holiday_multiplier', 4, 2)->default(1);
            $table->timestamp('face_consent_at')->nullable();
            $table->foreignId('face_consent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('face_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('descriptor');
            $table->string('model_version', 40)->default('human-faceres');
            $table->string('photo_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'revoked_at']);
        });

        Schema::create('attendance_networks', function (Blueprint $table) {
            $table->id();
            $table->string('label', 120);
            $table->string('cidr', 64)->comment('IPv4 (a.b.c.d/32 o rango) o prefijo IPv6 (…::/64)');
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('confirmed_from_mark_id')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('attendance_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('token_hash', 64)->unique();
            $table->foreignId('authorized_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('attendance_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('type', 3)->comment('in | out');
            $table->dateTime('marked_at')->comment('Hora del servidor; en una marca sin conexión, la del equipo');
            $table->dateTime('device_time')->nullable();
            $table->dateTime('received_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->foreignId('device_id')->nullable()->constrained('attendance_devices')->nullOnDelete();
            $table->boolean('network_ok')->default(false);
            $table->boolean('device_ok')->default(false);
            $table->decimal('face_score', 5, 4)->nullable();
            $table->boolean('liveness_ok')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('status', 20)->comment('valid | pending_network | face_review | offline_review | rejected | corrected');
            $table->string('source', 10)->default('kiosk')->comment('kiosk | offline | manual');
            $table->string('reason', 255)->nullable();
            $table->foreignId('corrected_from_id')->nullable()->constrained('attendance_marks')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 255)->nullable();
            $table->uuid('local_uuid')->nullable()->unique();
            $table->timestamps();
            $table->index(['user_id', 'marked_at']);
            $table->index(['status', 'marked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_marks');
        Schema::dropIfExists('attendance_devices');
        Schema::dropIfExists('attendance_networks');
        Schema::dropIfExists('face_enrollments');
        Schema::dropIfExists('employee_profiles');
    }
};
