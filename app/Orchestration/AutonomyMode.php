<?php

namespace App\Orchestration;

enum AutonomyMode: string
{
    case Manual = 'MANUAL';
    case Assisted = 'ASSISTED';
    case Controlled = 'CONTROLLED';
}
