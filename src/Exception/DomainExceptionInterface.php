<?php

declare(strict_types=1);

namespace App\Exception;

interface DomainExceptionInterface
{
    public function statusCode(): int;

    public function translationKey(): string;
}
