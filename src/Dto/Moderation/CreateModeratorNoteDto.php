<?php

declare(strict_types=1);

namespace App\Dto\Moderation;

use Symfony\Component\Validator\Constraints as Assert;

class CreateModeratorNoteDto
{
    #[Assert\NotBlank]
    public string $content;
}
