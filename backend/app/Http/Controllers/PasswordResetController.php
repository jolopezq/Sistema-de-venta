<?php

namespace App\Http\Controllers;

use App\Services\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Recuperar la contraseña desde el login con un código enviado al correo (rutas públicas, con límite de intentos).
 * Las respuestas no revelan si un correo está registrado.
 */
class PasswordResetController extends Controller
{
    private const CODE_ERROR = 'El código no es correcto o ya venció. Revísalo o pide uno nuevo.';

    public function __construct(private readonly PasswordResetService $service) {}

    /** Paso 1: pedir el código. */
    public function sendCode(Request $request): JsonResponse
    {
        $data = $request->validate(
            ['email' => ['required', 'string', 'email', 'max:255']],
            ['email.required' => 'Escribe tu correo.', 'email.email' => 'El formato del correo no es válido.'],
        );

        $this->service->request($data['email'], $request->ip());

        return response()->json([
            'message' => 'Si el correo está registrado, te enviamos un código de 6 dígitos. Vence en ' . PasswordResetService::CODE_MINUTES . ' minutos.',
            'resend_after' => PasswordResetService::RESEND_SECONDS,
        ]);
    }

    /** Paso 2: verificar el código; devuelve el permiso para poner la contraseña nueva. */
    public function verifyCode(Request $request): JsonResponse
    {
        $data = $request->validate(
            ['email' => ['required', 'string', 'email', 'max:255'], 'code' => ['required', 'string', 'regex:/^\d{6}$/']],
            ['code.required' => 'Escribe el código.', 'code.regex' => 'El código tiene 6 dígitos.'],
        );

        $token = $this->service->verify($data['email'], $data['code']);
        if (! $token) {
            throw ValidationException::withMessages(['code' => [self::CODE_ERROR]]);
        }

        return response()->json(['reset_token' => $token, 'expires_in' => PasswordResetService::RESET_MINUTES * 60]);
    }

    /** Paso 3: poner la contraseña nueva. */
    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate(
            [
                'email' => ['required', 'string', 'email', 'max:255'],
                'reset_token' => ['required', 'string', 'size:64'],
                'password' => ['required', 'string', 'min:6', 'max:255', 'confirmed'],
            ],
            [
                'password.required' => 'Escribe la contraseña nueva.',
                'password.min' => 'La contraseña nueva debe tener al menos 6 caracteres.',
                'password.confirmed' => 'Las dos contraseñas no coinciden.',
            ],
        );

        if (! $this->service->reset($data['email'], $data['reset_token'], $data['password'])) {
            throw ValidationException::withMessages(['reset_token' => ['El permiso para cambiar la contraseña venció. Pide un código nuevo.']]);
        }

        return response()->json(['message' => 'Listo: tu contraseña se cambió. Inicia sesión con la nueva.']);
    }
}
