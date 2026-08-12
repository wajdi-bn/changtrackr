<?php

namespace App\Services\Ocpp;

final class OcppSimulatorActionCatalog
{
    /** @var list<string> */
    public const DIAGNOSTIC_ACTIONS = [
        'heartbeat',
        'plug',
        'unplug',
        'inject_fault',
        'recover',
        'normal_cycle',
        'fault_recovery',
    ];

    /** @var list<string> */
    public const CONTROL_ACTIONS = [
        'connect',
        'disconnect',
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return [...self::CONTROL_ACTIONS, ...self::DIAGNOSTIC_ACTIONS];
    }

    public static function requiresControl(string $action): bool
    {
        return in_array($action, self::CONTROL_ACTIONS, true);
    }
}
