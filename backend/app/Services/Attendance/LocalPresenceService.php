<?php

namespace App\Services\Attendance;

use App\Models\AttendanceDevice;
use App\Models\AttendanceNetwork;

/**
 * ¿La marca se hizo en el local? Dos pruebas a la vez (docs/PLAN_ASISTENCIA_Y_SUELDOS.md §5.2):
 * que llegue desde una red del local y desde un equipo autorizado.
 *
 * La IP es la de $request->ip(): en el VPS, Nginx la toma de CF-Connecting-IP y solo acepta ese dato del túnel
 * (set_real_ip_from 127.0.0.1), así que desde internet no se puede inventar.
 */
class LocalPresenceService
{
    /**
     * @return array{ip: ?string, network_ok: bool, device_ok: bool, network: ?AttendanceNetwork}
     */
    public function evaluate(?string $ip, ?AttendanceDevice $device): array
    {
        $network = $ip ? $this->matchingNetwork($ip) : null;

        return [
            'ip'         => $ip,
            'network_ok' => $network !== null,
            'device_ok'  => $device !== null && $device->revoked_at === null,
            'network'    => $network,
        ];
    }

    public function matchingNetwork(string $ip): ?AttendanceNetwork
    {
        foreach (AttendanceNetwork::active()->get() as $network) {
            if ($this->ipMatches($ip, $network->cidr)) {
                return $network;
            }
        }

        return null;
    }

    /**
     * Red que se registra para una IP: la IPv4 exacta (/32) o el prefijo /64 de una IPv6, que cambia sus últimas
     * cuatro partes seguido aunque la conexión sea la misma.
     */
    public function networkFor(string $ip): string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            throw new \InvalidArgumentException("IP no válida: {$ip}");
        }
        if (strlen($packed) === 4) {
            return $ip . '/32';
        }
        $prefix = substr($packed, 0, 8) . str_repeat("\0", 8);

        return inet_ntop($prefix) . '/64';
    }

    /** ¿La IP está dentro del rango? Acepta «a.b.c.d», «a.b.c.d/n» y «prefijo-ipv6/n». */
    public function ipMatches(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', trim($cidr), 2), 2, null);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton((string) $subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }
        $maxBits = strlen($ipBin) * 8;
        $bits = $bits === null || $bits === '' ? $maxBits : (int) $bits;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }
        $fullBytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $fullBytes) !== substr($subnetBin, 0, $fullBytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($ipBin[$fullBytes]) & $mask) === (ord($subnetBin[$fullBytes]) & $mask);
    }

    /** ¿Es una red válida para guardar? */
    public function isValidCidr(string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', trim($cidr), 2), 2, null);
        $bin = @inet_pton((string) $subnet);
        if ($bin === false) {
            return false;
        }
        if ($bits === null) {
            return true;
        }

        return ctype_digit($bits) && (int) $bits >= 8 && (int) $bits <= strlen($bin) * 8;
    }
}
