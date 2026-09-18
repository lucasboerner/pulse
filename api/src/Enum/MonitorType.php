<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The kind of target a monitor watches.
 *
 * Version 1 ships HTTP only. Domain expiry, TLS and DNS arrive later as further
 * cases on this same enum and the same table, so nothing may be shaped as if HTTP
 * were the only case.
 */
enum MonitorType: string
{
    case Http = 'http';
}
