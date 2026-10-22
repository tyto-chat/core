<?php

declare(strict_types=1);

namespace App\Tests\Unit\State;

use ApiPlatform\Metadata\Get;
use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\User;
use App\Enum\Channel\ChannelType;
use App\Exception\AccessDeniedException;
use App\Service\Channel\ChannelServiceInterface;
use App\Service\Community\CommunityServiceInterface;
use App\Service\Conversation\ConversationServiceInterface;
use App\Service\Realtime\MercureSubscriberTokenFactory;
use App\Service\Realtime\RealtimeTopicResolver;
use App\State\Realtime\Provider\PublicRealtimeTokenProvider;
use App\State\Realtime\Provider\RealtimeTokenProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

final class RealtimeTokenProviderTest extends TestCase
{
    private const string KEY = 'unit_test_subscriber_key_at_least_32_bytes';

    public function testAnonymousCallerOnUserEndpointGetsNoToken(): void
    {
        $dto = $this->userProvider(null, [])->provide(new Get());

        self::assertNull($dto->token);
        self::assertNull($dto->expiresAt);
    }

    public function testUserTokenGrantsResolvedTopicsForRequestScope(): void
    {
        $user = $this->withId(new User(), 42);
        $before = time();

        $dto = $this->userProvider($user, ['community' => 'acme'])->provide(new Get());

        self::assertSame(
            ['u/42/n', 'u/42/e', 'u/42/d', 'u/{userId}/p', 'k/5', 'k/5/a', 'k/5/e', 'k/5/p', 'c/12', 'c/12/t'],
            $this->subscribeClaim((string) $dto->token),
        );
        self::assertGreaterThanOrEqual($before + 3600, $dto->expiresAt);
    }

    public function testPublicTokenGrantsPublicTopicsOnly(): void
    {
        $dto = $this->publicProvider(['community' => 'acme'])->provide(new Get());

        self::assertSame(['c/12', 'c/12/t', 'k/5', 'k/5/e'], $this->subscribeClaim((string) $dto->token));
    }

    public function testPublicTokenIsNullWithoutVisibleScope(): void
    {
        self::assertNull($this->publicProvider([])->provide(new Get())->token);
        self::assertNull($this->publicProvider(['community' => 'private-one'])->provide(new Get())->token);
    }

    /** @param array<string, string> $query */
    private function userProvider(?User $user, array $query): RealtimeTokenProvider
    {
        $security = self::createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return new RealtimeTokenProvider($this->factory(), $this->resolver(), $this->requests($query), $security);
    }

    /** @param array<string, string> $query */
    private function publicProvider(array $query): PublicRealtimeTokenProvider
    {
        return new PublicRealtimeTokenProvider($this->factory(), $this->resolver(), $this->requests($query));
    }

    private function factory(): MercureSubscriberTokenFactory
    {
        return new MercureSubscriberTokenFactory(self::KEY, 3600);
    }

    private function resolver(): RealtimeTopicResolver
    {
        $community = $this->withId((new Community())->setIdentifier('acme'), 5);
        $channel = $this->withId((new Channel())->setType(ChannelType::Text), 12);

        $communities = self::createStub(CommunityServiceInterface::class);
        $communities->method('getByIdentifier')->willReturnCallback(
            fn (string $id) => 'acme' === $id ? $community : throw new AccessDeniedException(),
        );
        $channels = self::createStub(ChannelServiceInterface::class);
        $channels->method('getViewableInCommunity')->willReturn([$channel]);
        $channels->method('getViewableTextChannels')->willReturn([$channel]);

        return new RealtimeTopicResolver($channels, $communities, self::createStub(ConversationServiceInterface::class));
    }

    /** @param array<string, string> $query */
    private function requests(array $query): RequestStack
    {
        $stack = new RequestStack();
        $stack->push(new Request($query));

        return $stack;
    }

    /** @return list<string> */
    private function subscribeClaim(string $jwt): array
    {
        $parts = explode('.', $jwt);
        self::assertCount(3, $parts);
        $decoded = base64_decode(strtr($parts[1], '-_', '+/'), true);
        self::assertIsString($decoded);
        $payload = json_decode($decoded, true, flags: \JSON_THROW_ON_ERROR);

        return $payload['mercure']['subscribe'];
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function withId(object $entity, int $id): object
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);

        return $entity;
    }
}
