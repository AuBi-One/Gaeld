<?php

namespace App\Domains\ImpactAccounting\Enums;

/** Distinguishes preservation work from changes to the exploitation model. */
enum PreservationActionType: string
{
    case Prevention = 'prevention';
    case Restoration = 'restoration';
    case Avoidance = 'avoidance';
}
