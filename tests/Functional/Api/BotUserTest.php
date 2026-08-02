<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class BotUserTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testBotUserCannotAuthenticate(): void
    {
        UserFactory::new()->bot()->withPassword('password123')->with(['email' => 'bot@tyto.test'])->create();

        $client = static::createClient();
        $client->request('POST', '/auth', [
            'json' => ['email' => 'bot@tyto.test', 'password' => 'password123'],
        ]);

        self::assertResponseStatusCodeSame(401);
    }

    public function testBotUserAbsentFromCommunityMemberList(): void
    {
        $user = UserFactory::createOne();
        $bot = UserFactory::new()->bot()->create();
        $community = CommunityFactory::new()->withIdentifier('bot-members')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        CommunityMemberFactory::createForUserAndCommunity($bot, $community);

        $response = $this->jsonClient($user)->request('GET', '/api/v1/communities/bot-members/members');

        self::assertResponseIsSuccessful();
        $members = $response->toArray()['hydra:member'];

        $memberIds = array_column($members, 'id');
        foreach ($memberIds as $member) {
        }

        foreach ($members as $member) {
            self::assertNotSame($bot->getId(), $member['user']['id'] ?? null);
        }
    }

    public function testBotUserAbsentFromUserSearchResults(): void
    {
        $admin = UserFactory::new()->admin()->create();
        UserFactory::new()->bot()->create();
        UserFactory::createOne();

        $response = $this->jsonClient($admin)->request('GET', '/api/v1/users');

        self::assertResponseIsSuccessful();
        $members = $response->toArray()['hydra:member'];

        foreach ($members as $member) {
            self::assertFalse($member['isBot'] ?? false, 'Bot user should not appear in user list.');
        }
    }

    public function testBotUserCannotBeUsedAsJwtToken(): void
    {
        $bot = UserFactory::new()->bot()->create();

        // jsonClient() generates a JWT for the user — verify bot can't use API
        $this->jsonClient($bot)->request('GET', '/api/v1/communities');

        // Bot user JWT is rejected by AuthUserChecker during JWT auth
        self::assertResponseStatusCodeSame(401);
    }
}
