<?php

declare(strict_types=1);

namespace App\Service\Admin;

use App\Dto\Admin\SetupStatusDto;

interface SetupStatusServiceInterface
{
    public function status(): SetupStatusDto;
}
