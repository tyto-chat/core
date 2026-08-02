<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Dto\Reaction\AddReactionDto;
use App\Repository\ReactionRepository;
use App\State\Reaction\Processor\AddReactionProcessor;
use App\State\Reaction\Processor\DeleteReactionProcessor;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Blameable\Traits\BlameableEntity;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ReactionRepository::class)]
#[ORM\UniqueConstraint(name: 'UNIQ_REACTION_MSG_USER_EMOJI', columns: ['message_id', 'created_by_id', 'emoji'])]
#[ApiResource(
    description: 'An emoji reaction by a user on a message; unique per message, user and emoji.',
    normalizationContext: ['groups' => ['reaction:read']],
    denormalizationContext: ['groups' => ['reaction:create']],
)]
#[Post(
    uriTemplate: '/messages/{messageId}/reactions',
    uriVariables: [
        'messageId' => new Link(
            fromClass: Message::class,
            identifiers: ['id']
        ),
    ],
    openapi: new Model\Operation(
        summary: 'React to a message',
        description: 'Adds a reaction for the caller, rebuilds the reactions cache of the message, publishes it '
            .'via Mercure and emits a `reaction.added` webhook event. Idempotent — repeating the same reaction '
            .'returns the existing row. Channel messages accept raw Unicode emoji plus `:shortcode:` custom '
            .'emojis registered for the community; direct messages accept Unicode only. Unrecognised or '
            .'unregistered emojis fail with `422`. Requires view access to the channel, or conversation '
            .'membership.',
    ),
    security: "is_granted('ROLE_USER')",
    input: AddReactionDto::class,
    read: false,
    processor: AddReactionProcessor::class,
    extraProperties: ['scopeResource' => 'messages'],
)]
#[Delete(
    openapi: new Model\Operation(
        summary: 'Remove a reaction',
        description: 'Deletes the reaction, rebuilds the reactions cache of the message and publishes it via '
            .'Mercure. Allowed for the user who reacted, or a global admin.',
    ),
    security: "is_granted('ROLE_USER')",
    processor: DeleteReactionProcessor::class,
    extraProperties: ['scopeResource' => 'messages'],
)]
class Reaction
{
    use BlameableEntity;
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['reaction:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 64)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    #[Groups(['reaction:read'])]
    private string $emoji;

    #[ORM\ManyToOne(targetEntity: Message::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    private ?Message $message = null;

    /** @var User|null */
    #[Gedmo\Blameable(on: 'create')]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['reaction:read'])]
    protected $createdBy;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmoji(): string
    {
        return $this->emoji;
    }

    public function setEmoji(string $emoji): static
    {
        $this->emoji = $emoji;

        return $this;
    }

    public function getMessage(): ?Message
    {
        return $this->message;
    }

    public function setMessage(?Message $message): static
    {
        $this->message = $message;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;

        return $this;
    }
}
