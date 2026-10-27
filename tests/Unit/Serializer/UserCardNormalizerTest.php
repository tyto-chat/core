<?php

declare(strict_types=1);

namespace App\Tests\Unit\Serializer;

use App\Dto\User\BlockedUserDto;
use App\Dto\User\InvitableUserDto;
use App\Entity\MediaObject;
use App\Serializer\MediaObject\AvatarUrlGeneratorInterface;
use App\Serializer\UserCardNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class UserCardNormalizerTest extends TestCase
{
    public function testFillsAvatarUrlFromTheAvatar(): void
    {
        $avatar = new MediaObject();
        $urls = $this->createMock(AvatarUrlGeneratorInterface::class);
        $urls->expects(self::once())->method('avatarUrl')->with($avatar)->willReturn('https://core.test/media/a.webp');
        $inner = $this->createMock(NormalizerInterface::class);
        $inner->expects(self::once())->method('normalize')->willReturnCallback(
            static fn (InvitableUserDto $dto): array => ['id' => $dto->id, 'avatarUrl' => $dto->avatarUrl],
        );
        $normalizer = new UserCardNormalizer($urls);
        $normalizer->setNormalizer($inner);

        $data = $normalizer->normalize(new InvitableUserDto(7, 'Quokka', $avatar));

        self::assertSame(['id' => 7, 'avatarUrl' => 'https://core.test/media/a.webp'], $data);
    }

    public function testSupportsCardDtosOnlyOnce(): void
    {
        $normalizer = new UserCardNormalizer(self::createStub(AvatarUrlGeneratorInterface::class));
        $blocked = new BlockedUserDto(1, null, '2026-10-27T00:00:00+00:00', true);

        self::assertTrue($normalizer->supportsNormalization($blocked));
        self::assertTrue($normalizer->supportsNormalization(new InvitableUserDto()));
        self::assertFalse($normalizer->supportsNormalization($blocked, null, ['USER_CARD_NORMALIZER_ALREADY_CALLED' => true]));
        self::assertFalse($normalizer->supportsNormalization(new \stdClass()));
    }
}
