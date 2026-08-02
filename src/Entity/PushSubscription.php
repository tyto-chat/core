<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Dto\PushSubscription\PushSubscriptionInputDto;
use App\Repository\PushSubscriptionRepository;
use App\State\PushSubscription\Processor\SubscribePushProcessor;
use App\State\PushSubscription\Processor\UnsubscribePushProcessor;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PushSubscriptionRepository::class)]
#[ORM\Table(name: 'push_subscription')]
#[ORM\UniqueConstraint(name: 'UNIQ_PUSH_SUBSCRIPTION_ENDPOINT', columns: ['endpoint'])]
#[ORM\Index(columns: ['user_id'], name: 'IDX_PUSH_SUBSCRIPTION_USER')]
#[ApiResource(
    description: 'A browser Web Push subscription for one of the caller\'s devices, keyed by its unique push endpoint.',
    operations: [
        new Post(
            uriTemplate: '/me/push-subscriptions',
            uriVariables: [],
            status: 201,
            security: "is_granted('ROLE_USER')",
            input: PushSubscriptionInputDto::class,
            output: false,
            processor: SubscribePushProcessor::class,
            extraProperties: ['scopeResource' => 'push'],
            openapi: new Model\Operation(
                summary: 'Register a Web Push subscription',
                description: 'The authenticated user. Idempotent upsert keyed on the push `endpoint`: '
                    .'an existing subscription (even one previously registered by another user of a '
                    .'shared device) is reclaimed for the caller and its keys refreshed. The per-device '
                    .'`locale` is stored so push bodies render in it. Returns `201` with an empty body.',
            ),
        ),
        new Delete(
            uriTemplate: '/me/push-subscriptions',
            uriVariables: [],
            security: "is_granted('ROLE_USER')",
            read: false,
            extraProperties: ['scopeResource' => 'push'],
            openapi: new Model\Operation(
                summary: 'Remove a Web Push subscription',
                description: 'The authenticated user. Deletes the subscription identified by the '
                    .'`endpoint` query parameter, but only when it belongs to the caller; otherwise '
                    .'a silent no-op. `400` when the `endpoint` parameter is missing.',
                parameters: [
                    new Model\Parameter('endpoint', 'query', 'The push endpoint URL to remove.', true),
                ],
            ),
            processor: UnsubscribePushProcessor::class,
        ),
    ],
    denormalizationContext: ['groups' => ['push_subscription:write']],
)]
class PushSubscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 512)]
    private string $endpoint;

    #[ORM\Column(length: 255)]
    private string $p256dh;

    #[ORM\Column(length: 255)]
    private string $authToken;

    #[ORM\Column(length: 8, options: ['default' => 'en'])]
    private string $locale = 'en';

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): static
    {
        $this->user = $user;

        return $this;
    }

    public function getEndpoint(): string
    {
        return $this->endpoint;
    }

    public function setEndpoint(string $endpoint): static
    {
        $this->endpoint = $endpoint;

        return $this;
    }

    public function getP256dh(): string
    {
        return $this->p256dh;
    }

    public function setP256dh(string $p256dh): static
    {
        $this->p256dh = $p256dh;

        return $this;
    }

    public function getAuthToken(): string
    {
        return $this->authToken;
    }

    public function setAuthToken(string $authToken): static
    {
        $this->authToken = $authToken;

        return $this;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
