<?php

declare(strict_types=1);

namespace App\Service\MediaObject;

use App\Entity\MediaObject;

interface AvatarUrlResolverInterface
{
    public function avatarUrl(?MediaObject $avatar): ?string;
}
