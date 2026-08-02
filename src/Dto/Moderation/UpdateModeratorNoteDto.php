<?php

declare(strict_types=1);

namespace App\Dto\Moderation;

use Symfony\Component\Validator\Constraints as Assert;

class UpdateModeratorNoteDto
{
    #[Assert\NotBlank]
    public string $content;
}
