<?php

declare(strict_types=1);

namespace App\Dto;

interface EntityDtoInterface
{
    public static function getEntityClass(): string;

    public function applyTo(object $entity): void;
}
