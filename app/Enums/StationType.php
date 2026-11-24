<?php

namespace App\Enums;

/**
 * The kind of work a service point performs. Deliberately industry-neutral:
 * the exact duty is described by the station's name, not by the type.
 */
enum StationType: string
{
    /** Intake / registration of a customer into the queue. */
    case Intake = 'INTAKE';

    /** General service delivery. */
    case Service = 'SERVICE';

    /** Point that takes a payment. */
    case Cashier = 'CASHIER';

    /** Any other operational counter. */
    case Counter = 'COUNTER';
}
