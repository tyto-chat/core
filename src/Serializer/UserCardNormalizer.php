<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Dto\User\BlockedUserDto;
use App\Dto\User\InvitableUserDto;
use App\Serializer\MediaObject\AvatarUrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class UserCardNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED = 'USER_CARD_NORMALIZER_ALREADY_CALLED';

    public function __construct(
        private readonly AvatarUrlGeneratorInterface $avatarUrls,
    ) {
    }

    /** @param array<mixed> $context */
    #[\Override]
    public function normalize(mixed $object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        \assert($object instanceof InvitableUserDto || $object instanceof BlockedUserDto);
        $context[self::ALREADY_CALLED] = true;
        $object->avatarUrl = $this->avatarUrls->avatarUrl($object->avatar);

        return $this->normalizer->normalize($object, $format, $context);
    }

    /** @param array<mixed> $context */
    #[\Override]
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return !isset($context[self::ALREADY_CALLED])
            && ($data instanceof InvitableUserDto || $data instanceof BlockedUserDto);
    }

    #[\Override]
    public function getSupportedTypes(?string $format): array
    {
        return [InvitableUserDto::class => false, BlockedUserDto::class => false];
    }
}
