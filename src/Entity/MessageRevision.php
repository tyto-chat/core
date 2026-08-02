<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model;
use App\Repository\MessageRevisionRepository;
use App\State\Message\Provider\MessageHistoryProvider;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Blameable\Traits\BlameableEntity;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\SerializedName;

#[ORM\Entity(repositoryClass: MessageRevisionRepository::class)]
#[ORM\Table(name: 'message_body')]
#[ApiResource(
    description: 'A single revision of the text of a message; one row is appended per edit.',
)]
#[GetCollection(
    uriTemplate: '/messages/{id}/history',
    uriVariables: ['id' => new Link(fromClass: Message::class)],
    openapi: new Model\Operation(
        summary: 'List the edit history of a message',
        description: 'Returns every body revision of the message in ascending creation order. Restricted to '
            .'moderation-capable users: global admins, community admins/moderators or channel moderators of '
            .'the channel the message belongs to; direct-message history is global-admin-only. Also available '
            .'for soft-deleted messages so moderators can audit tombstones.',
    ),
    security: "is_granted('ROLE_USER')",
    provider: MessageHistoryProvider::class,
    normalizationContext: ['groups' => ['message_history:read', 'user:embed']],
    extraProperties: ['scopeResource' => 'messages'],
)]
class MessageRevision
{
    use BlameableEntity;
    use TimestampableEntity;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(name: 'body', type: Types::TEXT)]
    #[Groups(['message:read', 'message_history:read'])]
    #[SerializedName('body')]
    private string $text = '';

    #[ORM\ManyToOne(inversedBy: 'revisions')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Message $message = null;

    /** @var \DateTimeInterface|null */
    #[Gedmo\Timestampable(on: 'create')]
    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    #[Groups(['message:read', 'message_history:read'])]
    protected $createdAt;

    /** @var User|null */
    #[Gedmo\Blameable(on: 'create')]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['message_history:read'])]
    protected $createdBy;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function setText(string $text): static
    {
        $this->text = $text;

        return $this;
    }

    public function getMessage(): ?Message
    {
        return $this->message;
    }

    public function setMessage(Message $message): static
    {
        $this->message = $message;

        return $this;
    }
}
