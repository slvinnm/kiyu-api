<?php

namespace App\Enums;

/**
 * Administrative grouping for the feature registry and platform portal.
 */
enum FeatureCategory: string
{
    case Core = 'core';
    case Report = 'report';
    case Export = 'export';
    case Notification = 'notification';
    case Capacity = 'capacity';
}
