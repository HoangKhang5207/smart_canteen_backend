<?php

namespace App\Enums;

enum OtpPurpose: string
{
    case BANK_LINK = 'BANK_LINK';
    case BANK_UNLINK = 'BANK_UNLINK';
}
