<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\Message;
use App\Enum\Message\MessageKind;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/** Strips `createdBy` from serialized system messages — the DB FK stays populated for audit. */
final class MessageNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED = 'MESSAGE_NORMALIZER_ALREADY_CALLED';

    #[\Override]
    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        $context[self::ALREADY_CALLED] = true;

        /** @var Message $object */
        /** @var array<string, mixed> $data */
        $data = $this->normalizer->normalize($object, $format, $context);

        if (MessageKind::System === $object->getKind()) {
            unset($data['createdBy']);
        }

        return $data;
    }

    #[\Override]
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        if (isset($context[self::ALREADY_CALLED])) {
            return false;
        }

        return $data instanceof Message;
    }

    #[\Override]
    public function getSupportedTypes(?string $format): array
    {
        return [Message::class => false];
    }
}
