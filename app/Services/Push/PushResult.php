<?php

namespace App\Services\Push;

enum PushResult: string
{
    case Delivered = 'delivered';
    case Expired = 'expired';
    case Retry = 'retry';
    case Rejected = 'rejected';
}
