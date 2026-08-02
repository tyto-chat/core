<?php

declare(strict_types=1);

namespace App\Dto\ChannelSection;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Dto\EntityDtoInterface;
use App\Entity\ChannelSection;
use App\State\ChannelSection\Processor\CreateChannelSectionProcessor;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    normalizationContext: ['groups' => ['section:read']],
    denormalizationContext: ['groups' => ['section:create', 'section:update']],
)]
#[Post(
    uriTemplate: '/communities/{community}/sections',
    security: "is_granted('ROLE_USER')",
    processor: CreateChannelSectionProcessor::class,
    extraProperties: ['scopeResource' => 'communities'],
    openapi: new Model\Operation(
        summary: 'Create a channel section',
        description: 'Requires community admin. Creates a named section in the given community, appended after'
            .' the existing sections, and broadcasts the structure change to connected clients.',
    ),
)]
readonly class CreateChannelSectionDto implements EntityDtoInterface
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Length(max: 255)]
        #[Groups(['section:create'])]
        public string $name,
    ) {
    }

    public static function getEntityClass(): string
    {
        return ChannelSection::class;
    }

    #[\Override]
    public function applyTo(object $entity): void
    {
        assert($entity instanceof ChannelSection);
        $entity->setName($this->name);
    }
}
