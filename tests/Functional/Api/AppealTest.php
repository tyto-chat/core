<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Community;
use App\Entity\User;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class AppealTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    /**
     * @return array{community: Community, mod: User, target: User, actionId: int}
     */
    private function seedWarn(string $identifier): array
    {
        $mod = UserFactory::createOne();
        $target = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier($identifier)->create();
        CommunityMemberFactory::createModeratorForCommunity($mod, $community);
        CommunityMemberFactory::createForUserAndCommunity($target, $community);

        $this->jsonClient($mod)->request('POST', '/api/v1/communities/'.$identifier.'/moderation', ['json' => [
            'targetUserId' => $target->getId(),
            'type' => 'warn',
            'reason' => 'Spam',
        ]]);
        self::assertResponseStatusCodeSame(201);
        $actionId = json_decode((string) self::getClient()->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];

        return ['community' => $community, 'mod' => $mod, 'target' => $target, 'actionId' => $actionId];
    }

    public function testTargetCanAppeal(): void
    {
        $seed = $this->seedWarn('appeal-basic');

        $this->jsonClient($seed['target'])->request('POST', '/api/v1/moderation-actions/'.$seed['actionId'].'/appeals', ['json' => [
            'reason' => 'I did not post spam.',
        ]]);

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) self::getClient()->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('pending', $data['status']);
    }

    public function testNonTargetCannotAppeal(): void
    {
        $seed = $this->seedWarn('appeal-other');
        $stranger = UserFactory::createOne();
        CommunityMemberFactory::createForUserAndCommunity($stranger, $seed['community']);

        $this->jsonClient($stranger)->request('POST', '/api/v1/moderation-actions/'.$seed['actionId'].'/appeals', ['json' => [
            'reason' => 'Not mine.',
        ]]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testDuplicateAppealRejected(): void
    {
        $seed = $this->seedWarn('appeal-dup');
        $payload = ['json' => ['reason' => 'Please review.']];

        $this->jsonClient($seed['target'])->request('POST', '/api/v1/moderation-actions/'.$seed['actionId'].'/appeals', $payload);
        self::assertResponseStatusCodeSame(201);

        $this->jsonClient($seed['target'])->request('POST', '/api/v1/moderation-actions/'.$seed['actionId'].'/appeals', $payload);
        self::assertResponseStatusCodeSame(422);
    }

    public function testModListsAndUpholdsAppeal(): void
    {
        $seed = $this->seedWarn('appeal-uphold');
        $this->jsonClient($seed['target'])->request('POST', '/api/v1/moderation-actions/'.$seed['actionId'].'/appeals', ['json' => [
            'reason' => 'Please review.',
        ]]);
        $appealId = json_decode((string) self::getClient()->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];

        $list = $this->jsonClient($seed['mod'])->request('GET', '/api/v1/communities/appeal-uphold/appeals')->toArray();
        self::assertCount(1, $list['member'] ?? $list['hydra:member']);

        $this->jsonClient($seed['mod'])->request('PATCH', '/api/v1/appeals/'.$appealId, [
            'json' => ['status' => 'upheld', 'resolutionNote' => 'Warning stands.'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('upheld', json_decode((string) self::getClient()->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['status']);
    }

    public function testOverturnLiftsAction(): void
    {
        $seed = $this->seedWarn('appeal-overturn');
        $this->jsonClient($seed['target'])->request('POST', '/api/v1/moderation-actions/'.$seed['actionId'].'/appeals', ['json' => [
            'reason' => 'Wrongly warned.',
        ]]);
        $appealId = json_decode((string) self::getClient()->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR)['id'];

        $this->jsonClient($seed['mod'])->request('PATCH', '/api/v1/appeals/'.$appealId, [
            'json' => ['status' => 'overturned'],
            'headers' => ['Content-Type' => 'application/merge-patch+json'],
        ]);
        self::assertResponseStatusCodeSame(200);

        $active = $this->jsonClient($seed['mod'])
            ->request('GET', '/api/v1/communities/appeal-overturn/users/'.$seed['target']->getId().'/active-moderation')
            ->toArray();
        self::assertCount(0, $active['member'] ?? $active['hydra:member']);
    }

    public function testNonModCannotListAppeals(): void
    {
        $seed = $this->seedWarn('appeal-noperm');

        $this->jsonClient($seed['target'])->request('GET', '/api/v1/communities/appeal-noperm/appeals');
        self::assertResponseStatusCodeSame(403);
    }
}
