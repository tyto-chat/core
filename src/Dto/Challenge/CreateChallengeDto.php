<?php

declare(strict_types=1);

namespace App\Dto\Challenge;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Dto\EntityDtoInterface;
use App\Entity\Challenge;
use App\State\Challenge\Processor\CreateChallengeProcessor;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    uriTemplate: '/challenges',
    description: 'A pre-registration email verification challenge.',
    operations: [
        new Post(
            output: false,
            processor: CreateChallengeProcessor::class,
            openapi: new Model\Operation(
                summary: 'Request an email verification challenge',
                description: 'Anonymous. Starts registration by creating a time-limited verification challenge for'
                    .' the given email address; when email validation is enabled the code is sent by mail.'
                    .' Always responds with success — an already-registered address is not disclosed.',
            ),
        ),
    ],
    denormalizationContext: ['groups' => ['challenge:create']],
)]
class CreateChallengeDto implements EntityDtoInterface
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        #[Groups(['challenge:create'])]
        public readonly string $email = '',
    ) {
    }

    public static function getEntityClass(): string
    {
        return Challenge::class;
    }

    #[\Override]
    public function applyTo(object $entity): void
    {
        assert($entity instanceof Challenge);
        $entity->setEmail($this->email);
    }
}
