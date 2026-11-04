<?php

/**
 * Reloj de asistencia (docs/PLAN_ASISTENCIA_Y_SUELDOS.md).
 */
return [
    // Similitud mínima (0 a 1) para que el rostro cuente como «verificado». Debajo, la marca queda «Rostro a revisar».
    // Se calibra en el local con la cámara y la luz reales (F3). Misma escala que Human: 0,5 ya se considera la misma persona.
    'face_threshold' => (float) env('ATTENDANCE_FACE_THRESHOLD', 0.55),

    // Una entrada sigue abierta (la marca siguiente es la salida) durante estas horas.
    'open_shift_hours' => 16,

    // Una segunda marca del mismo tipo dentro de estos segundos se ignora (doble toque).
    'double_tap_seconds' => 120,

    // PIN: intentos fallidos por persona y equipo antes de bloquear, y minutos de bloqueo.
    'pin_max_attempts' => 5,
    'pin_lock_minutes' => 15,

    // Días que se guardan las fotos de las marcas (después se borra la foto y la marca queda).
    'photo_retention_days' => 90,

    // Días que ve el trabajador en «Mis marcas».
    'my_marks_days' => 7,

    // Una marca sin conexión puede llegar hasta estos días después.
    'offline_max_days' => 7,

    // Fotos: tamaño máximo en bytes y carpeta dentro del disco local (storage/app/private).
    'photo_max_bytes' => 700 * 1024,
    'photo_dir' => 'asistencia',

    // Salario mínimo nacional mensual (Bs). 2026: Bs 3.300. Debajo de su valor por hora
    // (÷ 240) el perfil laboral avisa. Se actualiza cada año.
    'minimum_wage' => (float) env('ATTENDANCE_MINIMUM_WAGE', 3300),

    // Capturas activas del rostro por persona.
    'max_enrollments' => 5,
];
