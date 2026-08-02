<?php

declare(strict_types=1);

namespace App\Dto\Appeal;

use Symfony\Component\Validator\Constraints as Assert;

class CreateAppealDto
{
    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 2000)]
    public string $reason = '';
}
