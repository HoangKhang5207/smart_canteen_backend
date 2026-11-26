<?php

namespace App\Enums;

enum NotificationSeverity: string
{
    case INFO = 'INFO';
    case SUCCESS = 'SUCCESS';
    case WARNING = 'WARNING';
    case ERROR = 'ERROR';
}
