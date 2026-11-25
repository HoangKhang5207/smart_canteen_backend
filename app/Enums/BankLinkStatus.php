<?php

namespace App\Enums;

enum BankLinkStatus: string
{
    case PENDING_OTP = 'PENDING_OTP';
    case ACTIVE = 'ACTIVE';
    case UNLINKED = 'UNLINKED';
    case FAILED = 'FAILED';
    case EXPIRED = 'EXPIRED';
}
