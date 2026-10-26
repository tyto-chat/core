<?php

declare(strict_types=1);

namespace App\Service\MediaObject;

use App\Entity\MediaObject;

interface AttachmentUrlGeneratorInterface
{
    public function contentUrl(MediaObject $attachment): ?string;

    public function thumbnailUrl(MediaObject $attachment): ?string;
}
