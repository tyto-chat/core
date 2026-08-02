<?php

declare(strict_types=1);

namespace App\Enum\Webhook;

enum WebhookDeliveryStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Success = 'success';
    case Failed = 'failed';
}
