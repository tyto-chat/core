<?php

declare(strict_types=1);

namespace App\Dto\Message;

use App\Dto\EntityDtoInterface;
use App\Entity\Message;
use App\Validator\Constraint\MessageText;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

readonly class SendMessageDto implements EntityDtoInterface
{
    public function __construct(
        #[MessageText]
        #[Groups(['message:create'])]
        public string $text = '',
        /** @var list<string> IRIs of pre-uploaded pending MediaObject records */
        #[Groups(['message:create'])]
        public array $attachmentIris = [],
        #[Assert\Uuid(versions: [Assert\Uuid::V4_RANDOM])]
        #[Groups(['message:create'])]
        public ?string $id = null,
    ) {
    }

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        if ('' === trim($this->text) && 0 === count($this->attachmentIris)) {
            $context->buildViolation('This value should not be blank.')
                ->atPath('text')
                ->addViolation();
        }
    }

    public static function getEntityClass(): string
    {
        return Message::class;
    }

    #[\Override]
    public function applyTo(object $entity): void
    {
        assert($entity instanceof Message);
        $entity->setText($this->text);
    }
}
