<?php

namespace App\Http\Controllers\Attendance;

use App\Http\Controllers\Controller;
use App\Models\AttendanceDevice;
use App\Models\AttendanceNetwork;
use App\Services\Attendance\AttendanceSettings;
use App\Services\Attendance\LocalPresenceService;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Equipos autorizados, redes del local y ajustes del reloj (Asistencia → Equipos y redes).
 */
class AttendanceSetupController extends Controller
{
    public function __construct(
        private readonly LocalPresenceService $presence,
        private readonly AttendanceSettings $settings,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $ip = $request->ip();

        return response()->json([
            'current' => [
                'ip'          => $ip,
                'network'     => $ip ? $this->presence->networkFor($ip) : null,
                'network_ok'  => $ip ? $this->presence->matchingNetwork($ip) !== null : false,
            ],
            'networks' => AttendanceNetwork::with('addedBy:id,name')->active()->orderBy('id')->get()->map(fn ($n) => [
                'id'         => $n->id,
                'label'      => $n->label,
                'cidr'       => $n->cidr,
                'added_by'   => $n->addedBy?->name,
                'created_at' => $n->created_at?->format('Y-m-d H:i'),
            ]),
            'devices' => AttendanceDevice::with('authorizedBy:id,name')->active()->orderBy('id')->get()->map(fn ($d) => [
                'id'            => $d->id,
                'name'          => $d->name,
                'authorized_by' => $d->authorizedBy?->name,
                'created_at'    => $d->created_at?->format('Y-m-d H:i'),
                'last_seen_at'  => $d->last_seen_at?->format('Y-m-d H:i'),
                'last_ip'       => $d->last_ip,
            ]),
            'settings' => $this->settings->all(),
        ]);
    }

    /**
     * Autoriza el equipo desde el que se hace el pedido. La llave se devuelve una sola vez y el navegador la guarda;
     * el servidor guarda solo su huella. Con add_network, la red actual también queda como red del local.
     */
    public function storeDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'        => ['required', 'string', 'min:3', 'max:120'],
            'add_network' => ['nullable', 'boolean'],
        ], ['name.required' => 'Ponle un nombre al equipo.']);

        $token = Str::random(64);
        $device = AttendanceDevice::create([
            'name'          => trim($data['name']),
            'token_hash'    => AttendanceDevice::hashToken($token),
            'authorized_by' => $request->user()->id,
            'last_seen_at'  => now(),
            'last_ip'       => $request->ip(),
        ]);
        AuditLogger::log('attendance_device_authorized', 'Attendance',
            "Equipo «{$device->name}» autorizado para marcar asistencia por {$request->user()->name} (IP {$request->ip()}).", $request->user()->id);

        $network = null;
        if (($data['add_network'] ?? false) && $request->ip()) {
            $network = $this->addNetwork($request, 'Red del local');
        }

        return response()->json([
            'message' => 'Equipo autorizado.',
            'device'  => ['id' => $device->id, 'name' => $device->name],
            'token'   => $token,
            'network' => $network?->only(['id', 'label', 'cidr']),
        ], 201);
    }

    public function destroyDevice(Request $request, AttendanceDevice $device): JsonResponse
    {
        $device->update(['revoked_at' => now()]);
        AuditLogger::log('attendance_device_revoked', 'Attendance', "Equipo «{$device->name}» dejó de estar autorizado (por {$request->user()->name}).", $request->user()->id);

        return response()->json(['message' => 'El equipo ya no puede marcar asistencia.']);
    }

    /** Registra una red del local: la actual (sin cidr) o una escrita a mano. */
    public function storeNetwork(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:120'],
            'cidr'  => ['nullable', 'string', 'max:64'],
        ]);
        if (!empty($data['cidr']) && !$this->presence->isValidCidr($data['cidr'])) {
            throw ValidationException::withMessages(['cidr' => 'La red no es válida. Ejemplo: 181.115.1.20 o 2800:cd0:1a2b:3c4d::/64.']);
        }
        $network = $this->addNetwork($request, $data['label'] ?? 'Red del local', $data['cidr'] ?? null);

        return response()->json(['message' => 'Red del local registrada.', 'network' => $network->only(['id', 'label', 'cidr'])], 201);
    }

    public function destroyNetwork(Request $request, AttendanceNetwork $network): JsonResponse
    {
        $network->update(['revoked_at' => now()]);
        AuditLogger::log('attendance_network_removed', 'Attendance', "Red {$network->cidr} quitada de las redes del local por {$request->user()->name}.", $request->user()->id);

        return response()->json(['message' => 'Red quitada.']);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['require_entry_to_open_register' => ['required', 'boolean']]);
        $this->settings->set('require_entry_to_open_register', (bool) $data['require_entry_to_open_register']);
        AuditLogger::log('attendance_settings_changed', 'Attendance', sprintf(
            'Abrir la caja %s haber marcado la entrada (cambiado por %s).',
            $data['require_entry_to_open_register'] ? 'exige' : 'ya no exige', $request->user()->name
        ), $request->user()->id);

        return response()->json(['message' => 'Ajuste guardado.', 'settings' => $this->settings->all()]);
    }

    private function addNetwork(Request $request, string $label, ?string $cidr = null): AttendanceNetwork
    {
        $cidr = $cidr ? trim($cidr) : $this->presence->networkFor((string) $request->ip());
        $network = AttendanceNetwork::active()->where('cidr', $cidr)->first()
            ?? AttendanceNetwork::create(['label' => trim($label) ?: 'Red del local', 'cidr' => $cidr, 'added_by' => $request->user()->id]);
        AuditLogger::log('attendance_network_added', 'Attendance', "Red {$cidr} registrada como red del local por {$request->user()->name}.", $request->user()->id);

        return $network;
    }
}
