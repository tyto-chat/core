<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model;
use App\Repository\MediaObjectRepository;
use App\State\CommunityEmoji\Processor\UploadCustomEmojiProcessor;
use App\State\MediaObject\Processor\DeleteAttachmentProcessor;
use App\State\MediaObject\Processor\RemoveLogoProcessor;
use App\State\MediaObject\Processor\UpdateAvatarProcessor;
use App\State\MediaObject\Processor\UpdateLogoProcessor;
use App\State\MediaObject\Processor\UploadAttachmentProcessor;
use App\State\MediaObject\Processor\UploadConversationAttachmentProcessor;
use App\State\MediaObject\Provider\CommunityLogoProvider;
use App\Validator\Constraint\MediaUpload;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Blameable\Traits\BlameableEntity;
use Gedmo\Mapping\Annotation as Gedmo;
use Gedmo\Timestampable\Traits\TimestampableEntity;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

#[Vich\Uploadable]
#[ORM\Entity(repositoryClass: MediaObjectRepository::class)]
#[ApiResource(
    types: ['https://schema.org/MediaObject'],
    description: 'An uploaded file: user avatar, community logo, message attachment or custom emoji image.',
    operations: [
        new Post(
            uriTemplate: '/me/avatar',
            inputFormats: ['multipart' => ['multipart/form-data']],
            openapi: new Model\Operation(
                summary: 'Upload an avatar',
                description: 'The authenticated user. Uploads a new avatar image for the caller\'s own '
                    .'profile; any previous avatar is replaced and its file deleted. `422` when the '
                    .'file fails avatar validation (type/size).',
                requestBody: new Model\RequestBody(
                    content: new \ArrayObject([
                        'multipart/form-data' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'file' => [
                                        'type' => 'string',
                                        'format' => 'binary',
                                    ],
                                ],
                            ],
                        ],
                    ])
                )
            ),
            security: "is_granted('ROLE_USER')",
            validationContext: ['groups' => ['Default', 'avatar']],
            processor: UpdateAvatarProcessor::class,
            extraProperties: ['scopeResource' => 'profile'],
        ),
        new Post(
            uriTemplate: '/communities/{identifier}/logo',
            inputFormats: ['multipart' => ['multipart/form-data']],
            uriVariables: [],
            openapi: new Model\Operation(
                summary: 'Upload a community logo',
                description: 'Community admin of the target community. Uploads a new logo image; any '
                    .'previous logo is replaced and its file deleted. `404` when the community does '
                    .'not exist, `422` when the file fails logo validation.',
                requestBody: new Model\RequestBody(
                    content: new \ArrayObject([
                        'multipart/form-data' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'file' => [
                                        'type' => 'string',
                                        'format' => 'binary',
                                    ],
                                ],
                            ],
                        ],
                    ])
                )
            ),
            priority: 1,
            // Boundary gate only — the real community-admin authz lives in CommunityService::setLogo.
            security: "is_granted('ROLE_USER')",
            validationContext: ['groups' => ['Default', 'logo']],
            processor: UpdateLogoProcessor::class,
            extraProperties: ['scopeResource' => 'communities'],
        ),
        new Delete(
            uriTemplate: '/communities/{identifier}/logo',
            uriVariables: [],
            priority: 1,
            security: "is_granted('ROLE_USER')",
            provider: CommunityLogoProvider::class,
            processor: RemoveLogoProcessor::class,
            extraProperties: ['scopeResource' => 'communities'],
            openapi: new Model\Operation(
                summary: 'Remove a community logo',
                description: 'Community admin of the target community. Deletes the community\'s current '
                    .'logo and its file. `404` when the community does not exist or has no logo.',
            )
        ),
        new Post(
            uriTemplate: '/communities/{community}/channels/{channel}/attachments',
            inputFormats: ['multipart' => ['multipart/form-data']],
            uriVariables: [],
            openapi: new Model\Operation(
                summary: 'Upload an attachment to a channel',
                description: 'Any user who may view the channel. Uploads a file to be linked to a '
                    .'message on send. `403` when attachments are disabled for the channel, `404` '
                    .'when the community or channel does not exist, `422` when the file fails '
                    .'attachment validation.',
                requestBody: new Model\RequestBody(
                    content: new \ArrayObject([
                        'multipart/form-data' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'file' => [
                                        'type' => 'string',
                                        'format' => 'binary',
                                    ],
                                ],
                            ],
                        ],
                    ])
                )
            ),
            security: 'is_granted("ROLE_USER")',
            validationContext: ['groups' => ['Default', 'attachment']],
            processor: UploadAttachmentProcessor::class,
            extraProperties: ['scopeResource' => 'messages'],
        ),
        new Post(
            uriTemplate: '/conversations/{conversation}/attachments',
            inputFormats: ['multipart' => ['multipart/form-data']],
            uriVariables: [],
            openapi: new Model\Operation(
                summary: 'Upload an attachment to a conversation',
                description: 'Conversation participants only. Uploads a file to be linked to a direct '
                    .'message on send. `404` when the conversation does not exist, `422` when the '
                    .'file fails attachment validation.',
                requestBody: new Model\RequestBody(
                    content: new \ArrayObject([
                        'multipart/form-data' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'file' => [
                                        'type' => 'string',
                                        'format' => 'binary',
                                    ],
                                ],
                            ],
                        ],
                    ])
                )
            ),
            security: 'is_granted("ROLE_USER")',
            validationContext: ['groups' => ['Default', 'attachment']],
            processor: UploadConversationAttachmentProcessor::class,
            extraProperties: ['scopeResource' => 'conversations'],
        ),
        new Delete(
            uriTemplate: '/attachments/{id}',
            security: "is_granted('ROLE_USER')",
            processor: DeleteAttachmentProcessor::class,
            extraProperties: ['scopeResource' => 'messages'],
            openapi: new Model\Operation(
                summary: 'Delete an attachment',
                description: 'The uploader, a moderator of the channel the attached message lives in, '
                    .'or a global admin; DM attachments are uploader-only (plus global admin). Removes '
                    .'the file and republishes the parent message\'s attachment list. `400` when the '
                    .'media object is not an attachment.',
            )
        ),
        new Post(
            uriTemplate: '/communities/{community}/emojis/custom',
            inputFormats: ['multipart' => ['multipart/form-data']],
            uriVariables: [],
            openapi: new Model\Operation(
                summary: 'Upload a custom community emoji',
                description: 'Community admin of the target community. Uploads an emoji image and '
                    .'registers it under the given shortcode; an optional `name` labels it in pickers. '
                    .'`422` when the shortcode does not match `:[a-z0-9_-]{2,32}:`, is already taken '
                    .'in the community, or the image fails validation.',
                requestBody: new Model\RequestBody(
                    content: new \ArrayObject([
                        'multipart/form-data' => [
                            'schema' => [
                                'type' => 'object',
                                'properties' => [
                                    'shortcode' => ['type' => 'string'],
                                    'file' => ['type' => 'string', 'format' => 'binary'],
                                ],
                            ],
                        ],
                    ])
                )
            ),
            security: "is_granted('ROLE_USER')",
            validationContext: ['groups' => ['Default', 'community_emoji']],
            processor: UploadCustomEmojiProcessor::class,
            extraProperties: ['scopeResource' => 'communities'],
        ),
    ],
    outputFormats: ['jsonld' => ['application/ld+json']],
    normalizationContext: ['groups' => ['media_object:read']]
)]
class MediaObject
{
    use BlameableEntity;
    use TimestampableEntity;

    /** @var User|null */
    #[Gedmo\Blameable(on: 'create')]
    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    protected $createdBy;

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    #[ORM\Id]
    #[ORM\Column]
    #[ORM\GeneratedValue]
    private ?int $id = null;

    /** @var array{sm: string, md: string, lg: string}|string|null */
    #[ApiProperty(writable: false, types: ['https://schema.org/contentUrl'])]
    #[Groups(['media_object:read', 'user:read', 'message:read', 'community:read', 'community_emoji:read', 'member:read', 'channel_member:read', 'channel_participant:read', 'conversation:read', 'conversation_member:read', 'user:embed', 'invitable_users:read'])]
    public array|string|null $contentUrl = null;

    #[Vich\UploadableField(mapping: 'media_object', fileNameProperty: 'filePath')]
    #[Assert\NotNull]
    #[MediaUpload(type: 'avatar', groups: ['avatar'])]
    #[MediaUpload(type: 'logo', groups: ['logo'])]
    #[MediaUpload(type: 'attachment', groups: ['attachment'])]
    #[MediaUpload(type: 'community_emoji', groups: ['community_emoji'])]
    public ?File $file = null;

    #[ApiProperty(writable: false)]
    #[ORM\Column(nullable: true)]
    public ?string $filePath = null;

    #[ApiProperty(writable: false)]
    #[ORM\Column(length: 20, nullable: true)]
    public ?string $type = null;

    #[ApiProperty(writable: false)]
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['media_object:read', 'message:read'])]
    public ?string $originalName = null;

    #[ApiProperty(writable: false)]
    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['media_object:read', 'message:read', 'community_emoji:read'])]
    public ?string $mimeType = null;

    #[ApiProperty(writable: false)]
    #[ORM\Column(nullable: true)]
    #[Groups(['media_object:read', 'message:read'])]
    public ?int $size = null;

    #[ApiProperty(writable: false)]
    #[ORM\Column(nullable: true)]
    #[Groups(['media_object:read', 'message:read', 'community_emoji:read'])]
    public ?int $width = null;

    #[ApiProperty(writable: false)]
    #[ORM\Column(nullable: true)]
    #[Groups(['media_object:read', 'message:read', 'community_emoji:read'])]
    public ?int $height = null;

    #[ApiProperty(writable: false)]
    #[ORM\ManyToOne(targetEntity: Message::class, inversedBy: 'attachments')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    public ?Message $message = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
