<?php

namespace App\Enums;

/**
 * The kind of value a feature carries.
 */
enum FeatureValueType: string
{
    case Boolean = 'BOOLEAN';
    case Capacity = 'CAPACITY';
    case Quota = 'QUOTA';
}
