<?php

declare(strict_types=1);

namespace App\Tests\Functional\Conversation;

use App\Entity\Conversation;
use App\Entity\User;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ConversationPreflightTest extends ApiTestCase
{
    use ConversationPolicyFixturesTrait;
    use Factories;
    use ResetDatabase;

    public function testPreflightMatchesCreateOutcomeForEveryRow(): void
    {
        $rows = [
            'encrypted' => function (): array {
                [$a, $b] = $this->usersSharingCommunity(2);
                $this->reachable($a, $b);

                return [$a, [$b], 'encrypted'];
            },
            'plain fallback' => function (): array {
                [$a, $b] = $this->usersSharingCommunity(2);
                $this->reachable($a);

                return [$a, [$b], 'plain_fallback'];
            },
            'refusing peer unreachable' => function (): array {
                [$a, $b] = $this->usersSharingCommunity(2);
                $this->reachable($a);
                $this->refuses($b);

                return [$a, [$b], 'blocked'];
            },
            'plain group' => function (): array {
                [$a, $b, $c] = $this->usersSharingCommunity(3);
                $this->reachable($a, $b, $c);

                return [$a, [$b, $c], 'plain'];
            },
            'group with refusal' => function (): array {
                [$a, $b, $c] = $this->usersSharingCommunity(3);
                $this->refuses($b);

                return [$a, [$b, $c], 'blocked'];
            },
        ];

        foreach ($rows as $name => $row) {
            [$creator, $others, $expected] = $row();
            $preflight = $this->preflight($creator, $others);
            self::assertResponseIsSuccessful();
            self::assertSame($expected, $preflight['outcome'], $name);

            $encrypted = 'encrypted' === $expected;
            $this->create($creator, $others, $encrypted);
            self::assertResponseStatusCodeSame('blocked' === $expected ? 422 : 201, $name);
            if ('blocked' === $expected) {
                $this->create($creator, $others, !$encrypted);
                self::assertResponseStatusCodeSame(422, $name);
            }
        }
    }

    public function testPreflightReportsReasonAndUsers(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $this->reachable($a);
        $this->refuses($b);

        $body = $this->preflight($a, [$b]);

        self::assertSame([
            'outcome' => 'blocked',
            'reason' => 'participant_refuses',
            'refusingUserIds' => [$b->getId()],
            'unreachableUserIds' => [$b->getId()],
            'existingIdentifier' => null,
        ], $body);
    }

    public function testPreflightInDisabledModeIsPlain(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $this->reachable($a, $b);
        $this->storeMode('disabled');

        $body = $this->preflight($a, [$b]);

        self::assertSame('plain', $body['outcome']);
        self::assertSame('operator_disabled', $body['reason']);
        $this->create($a, [$b], false);
        self::assertResponseStatusCodeSame(201);
    }

    public function testPreflightCreatesNothing(): void
    {
        [$a, $b, $c] = $this->usersSharingCommunity(3);
        $this->reachable($a, $b);

        $this->preflight($a, [$b]);
        $this->preflight($a, [$b, $c]);

        self::assertSame(0, $this->em()->getRepository(Conversation::class)->count([]));
    }

    public function testPreflightBlockErrorIdenticalToCreate(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $this->refuses($b);
        $this->plainJsonClient($b)->request('POST', '/api/v1/me/blocks', ['json' => ['userId' => $a->getId()]]);
        self::assertResponseStatusCodeSame(201);

        $preflight = $this->plainJsonClient($a)->request('GET', $this->preflightUrl([$b]));
        $create = $this->plainJsonClient($a)->request('POST', '/api/v1/conversations', ['json' => ['memberUserIds' => [$b->getId()]]]);

        self::assertSame(422, $preflight->getStatusCode());
        self::assertSame($create->getStatusCode(), $preflight->getStatusCode());
        self::assertSame($create->toArray(false), $preflight->toArray(false));
    }

    public function testPreflightReturnsExistingIdentifier(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $this->reachable($a, $b);
        $created = $this->create($a, [$b], true);

        $body = $this->preflight($b, [$a]);

        self::assertSame('encrypted', $body['outcome']);
        self::assertSame($created['identifier'], $body['existingIdentifier']);
    }

    public function testPreflightReturnsExistingIdentifierDespiteBlock(): void
    {
        [$a, $b] = $this->usersSharingCommunity(2);
        $created = $this->create($a, [$b], false);
        $this->plainJsonClient($b)->request('POST', '/api/v1/me/blocks', ['json' => ['userId' => $a->getId()]]);

        $body = $this->preflight($a, [$b]);

        self::assertSame($created['identifier'], $body['existingIdentifier']);
    }

    public function testPreflightNeedsSharedCommunityLikeCreate(): void
    {
        [$a] = $this->usersSharingCommunity(1);
        $stranger = UserFactory::createOne();

        $this->plainJsonClient($a)->request('GET', $this->preflightUrl([$stranger]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testPreflightNeedsAuthentication(): void
    {
        $this->plainJsonClient()->request('GET', '/api/v1/conversations/preflight?memberUserIds[]=1');

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @param list<User> $members
     *
     * @return array<string, mixed>
     */
    private function preflight(User $as, array $members): array
    {
        return $this->plainJsonClient($as)->request('GET', $this->preflightUrl($members))->toArray(false);
    }

    /** @param list<User> $members */
    private function preflightUrl(array $members): string
    {
        return '/api/v1/conversations/preflight?'.implode('&', array_map(static fn (User $u): string => 'memberUserIds[]='.$u->getId(), $members));
    }

    /**
     * @param list<User> $members
     *
     * @return array<string, mixed>
     */
    private function create(User $as, array $members, bool $encrypted): array
    {
        return $this->plainJsonClient($as)->request('POST', '/api/v1/conversations', [
            'json' => ['memberUserIds' => array_map(static fn (User $u): ?int => $u->getId(), $members), 'encrypted' => $encrypted],
        ])->toArray(false);
    }
}
