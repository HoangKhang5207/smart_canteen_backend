<?php

namespace App\Enums;

enum SandboxBankMode: string
{
    case NORMAL = 'NORMAL';
    case INSUFFICIENT_FUNDS = 'INSUFFICIENT_FUNDS';
    case BANK_DECLINED = 'BANK_DECLINED';
    case GATEWAY_TIMEOUT = 'GATEWAY_TIMEOUT';
}
