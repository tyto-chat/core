<?php

declare(strict_types=1);

namespace App\Tests\Unit\Serializer;

use App\Entity\UserGroup;
use App\Serializer\UserGroupNormalizer;
use App\Service\UserGroup\UserGroupServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

#[AllowMockObjectsWithoutExpectations]
class UserGroupNormalizerTest extends TestCase
{
    private UserGroupServiceInterface&MockObject $userGroupService;
    private NormalizerInterface&MockObject $innerNormalizer;
    private UserGroupNormalizer $normalizer;

    #[\Override]
    protected function setUp(): void
    {
        $this->userGroupService = $this->createMock(UserGroupServiceInterface::class);
        $this->innerNormalizer = $this->createMock(NormalizerInterface::class);

        $this->normalizer = new UserGroupNormalizer($this->userGroupService);
        $this->normalizer->setNormalizer($this->innerNormalizer);
    }

    private function group(): UserGroup
    {
        return (new UserGroup())->setCommunity(new \App\Entity\Community());
    }

    public function testSupportsUserGroupWithoutAlreadyCalledFlag(): void
    {
        self::assertTrue($this->normalizer->supportsNormalization($this->group()));
    }

    public function testDoesNotSupportWhenAlreadyCalledFlagSet(): void
    {
        self::assertFalse(
            $this->normalizer->supportsNormalization($this->group(), null, ['USER_GROUP_NORMALIZER_ALREADY_CALLED' => true])
        );
    }

    public function testDoesNotSupportNonUserGroupObject(): void
    {
        self::assertFalse($this->normalizer->supportsNormalization(new \stdClass()));
    }

    public function testInjectsMemberCount(): void
    {
        $this->userGroupService->method('countMembers')->willReturn(7);
        $this->innerNormalizer->method('normalize')->willReturn(['id' => 1, 'name' => 'devs']);

        $result = $this->normalizer->normalize($this->group());

        self::assertSame(7, $result['memberCount']);
    }

    public function testPreservesInnerNormalizerFields(): void
    {
        $this->userGroupService->method('countMembers')->willReturn(3);
        $this->innerNormalizer->method('normalize')->willReturn(['id' => 42, 'name' => 'admins']);

        $result = $this->normalizer->normalize($this->group());

        self::assertSame(42, $result['id']);
        self::assertSame('admins', $result['name']);
        self::assertSame(3, $result['memberCount']);
    }

    public function testZeroMemberCount(): void
    {
        $this->userGroupService->method('countMembers')->willReturn(0);
        $this->innerNormalizer->method('normalize')->willReturn(['id' => 1]);

        $result = $this->normalizer->normalize($this->group());

        self::assertSame(0, $result['memberCount']);
    }
}
