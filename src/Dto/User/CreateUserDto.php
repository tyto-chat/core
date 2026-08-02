<?php

declare(strict_types=1);

namespace App\Dto\User;

use App\Dto\EntityDtoInterface;
use App\Entity\User;
use App\Validator\EmailAvailable;
use App\Validator\ValidChallenge;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

#[ValidChallenge]
class CreateUserDto implements EntityDtoInterface
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        #[EmailAvailable]
        public readonly string $email,
        #[Assert\NotBlank(message: 'Password cannot be blank.')]
        #[Assert\Length(min: 8, max: 64)]
        #[SerializedName('password')]
        public readonly string $plainPassword,
        #[Assert\NotBlank]
        #[Assert\Length(min: 4, max: 255)]
        #[SerializedName('displayName')]
        public readonly string $displayName = '',
        #[SerializedName('challenge')]
        public readonly ?string $challengeToken = null,
        #[SerializedName('acceptedTerms')]
        public readonly bool $acceptedTerms = false,
        #[Assert\Date(message: 'dateOfBirth must be a valid YYYY-MM-DD date.')]
        #[SerializedName('dateOfBirth')]
        public readonly ?string $dateOfBirth = null,
    ) {
    }

    public static function getEntityClass(): string
    {
        return User::class;
    }

    #[\Override]
    public function applyTo(object $entity): void
    {
        assert($entity instanceof User);
        $entity->setEmail($this->email);
    }
}
