<?php

declare(strict_types=1);

namespace App\Exception\User;

use App\Exception\DomainExceptionInterface;
use App\Exception\TranslationParamsInterface;

class RegistrationBlockedException extends \DomainException implements DomainExceptionInterface, TranslationParamsInterface
{
    public function __construct(private readonly ?string $appealContact = null)
    {
        parent::__construct('Registration blocked by reputation check.');
    }

    #[\Override]
    public function statusCode(): int
    {
        return 422;
    }

    #[\Override]
    public function translationKey(): string
    {
        return null !== $this->appealContact && '' !== $this->appealContact
            ? 'exception.registration_blocked_contact'
            : 'exception.registration_blocked';
    }

    #[\Override]
    public function translationParams(): array
    {
        return ['%contact%' => (string) $this->appealContact];
    }
}
