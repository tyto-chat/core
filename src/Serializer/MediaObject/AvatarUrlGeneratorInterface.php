<?php

declare(strict_types=1);

namespace App\Serializer\MediaObject;

use App\Entity\MediaObject;

interface AvatarUrlGeneratorInterface
{
    public function avatarUrl(?MediaObject $avatar): ?string;
}
