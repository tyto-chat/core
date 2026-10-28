<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\User;
use App\Entity\UserPreference;
use App\Enum\Conversation\PolicyOutcome;
use App\Enum\Conversation\PolicyReason;
use App\Enum\Settings\EncryptedDmsMode;
use App\Security\ConversationPolicy;
use App\Service\E2ee\E2eeServiceInterface;
use App\Service\User\UserPreferenceServiceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConversationPolicyTest extends TestCase
{
    private const int CREATOR = 1;

    /**
     * @param list<int> $others
     * @param list<int> $refusing
     * @param list<int> $unreachable
     * @param list<int> $expectedRefusing
     * @param list<int> $expectedUnreachable
     */
    #[DataProvider('rules')]
    public function testDecidesEveryRuleTableRow(
        EncryptedDmsMode $mode,
        array $others,
        array $refusing,
        array $unreachable,
        PolicyOutcome $outcome,
        bool $mustEncrypt,
        ?PolicyReason $reason,
        array $expectedRefusing,
        array $expectedUnreachable,
    ): void {
        $decision = $this->policy($mode, $refusing, $unreachable)->decide(
            self::user(self::CREATOR),
            array_map(self::user(...), $others),
        );

        self::assertSame($outcome, $decision->outcome);
        self::assertSame($mustEncrypt, $decision->mustEncrypt);
        self::assertSame($reason, $decision->reason);
        self::assertSame($expectedRefusing, $decision->refusingUserIds);
        self::assertSame($expectedUnreachable, $decision->unreachableUserIds);
    }

    /** @return iterable<string, array{EncryptedDmsMode, list<int>, list<int>, list<int>, PolicyOutcome, bool, ?PolicyReason, list<int>, list<int>}> */
    public static function rules(): iterable
    {
        $enabled = EncryptedDmsMode::Enabled;
        $required = EncryptedDmsMode::Required;
        $disabled = EncryptedDmsMode::Disabled;

        yield 'enabled, everyone reachable: encrypted, no plain option' => [$enabled, [2], [], [], PolicyOutcome::Encrypted, false, null, [], []];
        yield 'enabled, peer unreachable: plain fallback' => [$enabled, [2], [], [2], PolicyOutcome::PlainFallback, false, null, [], [2]];
        yield 'enabled, creator unreachable: plain fallback' => [$enabled, [2], [], [1], PolicyOutcome::PlainFallback, false, null, [], [1]];
        yield 'enabled, creator refuses, everyone reachable' => [$enabled, [2], [1], [], PolicyOutcome::Encrypted, true, PolicyReason::SelfRefuses, [1], []];
        yield 'enabled, creator refuses and is unreachable' => [$enabled, [2], [1], [1], PolicyOutcome::Blocked, true, PolicyReason::SelfRefuses, [1], [1]];
        yield 'enabled, peer refuses, everyone reachable' => [$enabled, [2], [2], [], PolicyOutcome::Encrypted, true, PolicyReason::ParticipantRefuses, [2], []];
        yield 'enabled, peer refuses and is unreachable' => [$enabled, [2], [2], [2], PolicyOutcome::Blocked, true, PolicyReason::ParticipantRefuses, [2], [2]];
        yield 'enabled, both refuse: own refusal named first' => [$enabled, [2], [1, 2], [], PolicyOutcome::Encrypted, true, PolicyReason::SelfRefuses, [1, 2], []];
        yield 'required, everyone reachable' => [$required, [2], [], [], PolicyOutcome::Encrypted, true, PolicyReason::OperatorRequired, [], []];
        yield 'required, peer unreachable' => [$required, [2], [], [2], PolicyOutcome::Blocked, true, PolicyReason::OperatorRequired, [], [2]];
        yield 'required, peer also refuses: operator reason wins' => [$required, [2], [2], [], PolicyOutcome::Encrypted, true, PolicyReason::OperatorRequired, [2], []];
        yield 'disabled, nobody refuses' => [$disabled, [2], [], [], PolicyOutcome::Plain, false, PolicyReason::OperatorDisabled, [], []];
        yield 'disabled, peer refuses' => [$disabled, [2], [2], [], PolicyOutcome::Blocked, true, PolicyReason::ParticipantRefuses, [2], []];
        yield 'disabled, creator refuses' => [$disabled, [2], [1], [], PolicyOutcome::Blocked, true, PolicyReason::SelfRefuses, [1], []];
        yield 'group, enabled, everyone reachable: still plain' => [$enabled, [2, 3], [], [], PolicyOutcome::Plain, false, null, [], []];
        yield 'group, enabled, member refuses' => [$enabled, [2, 3], [3], [], PolicyOutcome::Blocked, true, PolicyReason::ParticipantRefuses, [3], []];
        yield 'group, enabled, creator refuses' => [$enabled, [2, 3], [1], [], PolicyOutcome::Blocked, true, PolicyReason::SelfRefuses, [1], []];
        yield 'group, required' => [$required, [2, 3], [], [], PolicyOutcome::Blocked, true, PolicyReason::GroupNotAvailable, [], []];
        yield 'group, disabled, nobody refuses' => [$disabled, [2, 3], [], [], PolicyOutcome::Plain, false, PolicyReason::OperatorDisabled, [], []];
        yield 'group, disabled, member refuses' => [$disabled, [2, 3], [2], [], PolicyOutcome::Blocked, true, PolicyReason::ParticipantRefuses, [2], []];
    }

    public function testBotPeerIsNeverReachable(): void
    {
        $bot = self::user(2);
        $bot->setIsBot(true);

        $decision = $this->policy(EncryptedDmsMode::Enabled, [], [])->decide(self::user(self::CREATOR), [$bot]);

        self::assertSame(PolicyOutcome::PlainFallback, $decision->outcome);
        self::assertSame([2], $decision->unreachableUserIds);
    }

    /**
     * @param list<int> $refusing
     * @param list<int> $unreachable
     */
    private function policy(EncryptedDmsMode $mode, array $refusing, array $unreachable): ConversationPolicy
    {
        $e2ee = self::createStub(E2eeServiceInterface::class);
        $e2ee->method('mode')->willReturn($mode);
        $e2ee->method('isReachable')->willReturnCallback(static fn (User $u): bool => !in_array($u->getId(), $unreachable, true));

        $preferences = self::createStub(UserPreferenceServiceInterface::class);
        $preferences->method('getForUser')->willReturnCallback(static fn (User $u): UserPreference => (new UserPreference($u))->setRefusePlainDms(in_array($u->getId(), $refusing, true) ? true : null));

        return new ConversationPolicy($e2ee, $preferences);
    }

    private static function user(int $id): User
    {
        $user = new User();
        new \ReflectionProperty(User::class, 'id')->setValue($user, $id);

        return $user;
    }
}
