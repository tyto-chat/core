<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Channel;
use App\Entity\Community;
use App\Entity\Profile;
use App\Entity\User;
use App\Enum\Channel\ChannelType;
use App\Enum\Message\MessageKind;
use App\Exception\User\UserNotFoundException;
use App\Service\Message\MessageServiceInterface;
use App\Service\Message\WelcomeMessageService;
use App\Service\User\BotUserServiceInterface;
use App\Service\UserContextServiceInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
class WelcomeMessageServiceTest extends TestCase
{
    private BotUserServiceInterface&MockObject $botUserService;
    private UserContextServiceInterface&MockObject $userContextService;
    private MessageServiceInterface&MockObject $messageService;
    private TranslatorInterface&MockObject $translator;
    private WelcomeMessageService $service;

    #[\Override]
    protected function setUp(): void
    {
        $this->botUserService = $this->createMock(BotUserServiceInterface::class);
        $this->userContextService = $this->createMock(UserContextServiceInterface::class);
        $this->messageService = $this->createMock(MessageServiceInterface::class);
        $this->translator = $this->createMock(TranslatorInterface::class);

        $this->service = new WelcomeMessageService(
            $this->botUserService,
            $this->userContextService,
            $this->messageService,
            $this->translator,
            new NullLogger(),
        );

        // runAs invokes the closure synchronously so the inner sendToChannel
        // call still runs against our mock.
        $this->userContextService->method('runAs')
            ->willReturnCallback(static fn (User $u, callable $fn) => $fn());
    }

    private function newMember(int $id, string $name = 'Alice'): User
    {
        $profile = $this->createMock(Profile::class);
        $profile->method('getName')->willReturn($name);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getProfile')->willReturn($profile);

        return $user;
    }

    private function community(?Channel $welcomeChannel, string $locale = 'en', int $id = 1): Community
    {
        $community = $this->createMock(Community::class);
        $community->method('getId')->willReturn($id);
        $community->method('getWelcomeChannel')->willReturn($welcomeChannel);
        $community->method('getLocale')->willReturn($locale);

        return $community;
    }

    private function channel(ChannelType $type, bool $isPrivate, int $communityId = 1): Channel
    {
        $channel = $this->createMock(Channel::class);
        $channel->method('getType')->willReturn($type);
        $channel->method('isPrivate')->willReturn($isPrivate);
        $owner = $this->createMock(Community::class);
        $owner->method('getId')->willReturn($communityId);
        $channel->method('getCommunity')->willReturn($owner);

        return $channel;
    }

    public function testSkipsWhenNoWelcomeChannel(): void
    {
        $this->messageService->expects(self::never())->method('sendToChannel');

        $this->service->sendIfConfigured($this->community(null), $this->newMember(7));
    }

    public function testSkipsWhenChannelIsPrivate(): void
    {
        $channel = $this->channel(ChannelType::Text, isPrivate: true);
        $this->messageService->expects(self::never())->method('sendToChannel');

        $this->service->sendIfConfigured($this->community($channel), $this->newMember(7));
    }

    public function testSkipsWhenChannelIsAudio(): void
    {
        $channel = $this->channel(ChannelType::Audio, isPrivate: false);
        $this->messageService->expects(self::never())->method('sendToChannel');

        $this->service->sendIfConfigured($this->community($channel), $this->newMember(7));
    }

    public function testSkipsWhenChannelBelongsToDifferentCommunity(): void
    {
        $channel = $this->channel(ChannelType::Text, isPrivate: false, communityId: 99);
        $this->messageService->expects(self::never())->method('sendToChannel');

        $this->service->sendIfConfigured($this->community($channel, id: 1), $this->newMember(7));
    }

    public function testSkipsWhenBotMissing(): void
    {
        $channel = $this->channel(ChannelType::Text, isPrivate: false);
        $this->botUserService->method('getWelcomeBot')
            ->willThrowException(new \RuntimeException('The defaultBotId server setting is not configured.'));
        $this->messageService->expects(self::never())->method('sendToChannel');

        $this->service->sendIfConfigured($this->community($channel), $this->newMember(7));
    }

    public function testSkipsWhenBotUserNotFound(): void
    {
        $channel = $this->channel(ChannelType::Text, isPrivate: false);
        $this->botUserService->method('getWelcomeBot')
            ->willThrowException(new UserNotFoundException('Bot user with id "99" not found.'));
        $this->messageService->expects(self::never())->method('sendToChannel');

        $this->service->sendIfConfigured($this->community($channel), $this->newMember(7));
    }

    public function testPostsSystemMessageWithMentionInSupportedLocale(): void
    {
        $channel = $this->channel(ChannelType::Text, isPrivate: false);
        $bot = $this->createMock(User::class);
        $this->botUserService->method('getWelcomeBot')->willReturn($bot);

        $this->translator->expects(self::once())
            ->method('trans')
            ->with(
                self::stringStartsWith('welcome.template_'),
                self::callback(function (array $params): bool {
                    return isset($params['%user%'])
                        && '[@Alice](user:42)' === $params['%user%'];
                }),
                'messages',
                'pl',
            )
            ->willReturn('Witaj [@Alice](user:42)');

        $this->messageService->expects(self::once())
            ->method('sendToChannel')
            ->with(
                $channel,
                'Witaj [@Alice](user:42)',
                [],
                MessageKind::System,
            );

        $this->service->sendIfConfigured(
            $this->community($channel, 'pl'),
            $this->newMember(42, 'Alice'),
        );
    }

    public function testStripsBracketsFromMentionName(): void
    {
        // `[` and `]` in a display name would break the markdown link parser;
        // the service must strip them so the rendered mention stays well-formed.
        $channel = $this->channel(ChannelType::Text, isPrivate: false);
        $bot = $this->createMock(User::class);
        $this->botUserService->method('getWelcomeBot')->willReturn($bot);

        $this->translator->method('trans')
            ->willReturnCallback(function (string $id, array $params): string {
                self::assertSame('[@badname](user:1)', $params['%user%']);

                return 'ok';
            });

        $this->messageService->expects(self::once())->method('sendToChannel');

        $this->service->sendIfConfigured(
            $this->community($channel),
            $this->newMember(1, '[bad]name'),
        );
    }

    public function testEveryLocaleCatalogHasAllWelcomeTemplates(): void
    {
        $catalogs = glob(__DIR__.'/../../../translations/messages.*.yaml');
        self::assertNotFalse($catalogs);
        foreach ($catalogs as $catalog) {
            $content = (string) file_get_contents($catalog);
            for ($i = 1; $i <= 5; ++$i) {
                self::assertStringContainsString(
                    sprintf('template_%d:', $i),
                    $content,
                    sprintf('%s is missing welcome.template_%d', basename($catalog), $i),
                );
            }
        }
    }
}
