<?php

declare(strict_types=1);

namespace App\Dto;

// Properties of using DTOs must stay uninitialized (no defaults), else omitted is indistinguishable from null.
trait PatchDtoTrait
{
    public function isProvided(string $property): bool
    {
        return new \ReflectionProperty($this, $property)->isInitialized($this);
    }
}
