<?php

namespace App\Enums;

enum StudentCardStatus: string
{
    case ACTIVE = 'ACTIVE';
    case LOCKED = 'LOCKED';
    case REVOKED = 'REVOKED';
}
