<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ReorderSectionsTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testAdminReordersSections(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('reorder-sections')->create();

        $responseA = $this->jsonClient($admin)->request('POST', '/api/v1/communities/reorder-sections/sections', ['json' => [
            'name' => 'Section A',
        ]]);
        self::assertResponseStatusCodeSame(201);
        $idA = $responseA->toArray()['id'];

        $responseB = $this->jsonClient($admin)->request('POST', '/api/v1/communities/reorder-sections/sections', ['json' => [
            'name' => 'Section B',
        ]]);
        self::assertResponseStatusCodeSame(201);
        $idB = $responseB->toArray()['id'];

        $responseC = $this->jsonClient($admin)->request('POST', '/api/v1/communities/reorder-sections/sections', ['json' => [
            'name' => 'Section C',
        ]]);
        self::assertResponseStatusCodeSame(201);
        $idC = $responseC->toArray()['id'];

        $this->jsonClient($admin)->request('PUT', '/api/v1/communities/reorder-sections/sections/order', [
            'json' => ['sections' => [$idC, $idA, $idB]],
        ]);
        self::assertResponseStatusCodeSame(204);

        $response = $this->jsonClient($admin)->request('GET', '/api/v1/communities/reorder-sections');
        self::assertResponseStatusCodeSame(200);

        $data = $response->toArray();
        $returnedIds = array_map(static fn (array $s) => $s['id'], $data['channelSections']);
        self::assertSame([$idC, $idA, $idB], $returnedIds);
    }

    public function testRejectsIncompleteSet(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('reorder-incomplete')->create();

        $responseA = $this->jsonClient($admin)->request('POST', '/api/v1/communities/reorder-incomplete/sections', ['json' => [
            'name' => 'Section A',
        ]]);
        self::assertResponseStatusCodeSame(201);
        $idA = $responseA->toArray()['id'];

        $this->jsonClient($admin)->request('POST', '/api/v1/communities/reorder-incomplete/sections', ['json' => [
            'name' => 'Section B',
        ]]);
        self::assertResponseStatusCodeSame(201);

        $this->jsonClient($admin)->request('PUT', '/api/v1/communities/reorder-incomplete/sections/order', [
            'json' => ['sections' => [$idA]],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testRejectsForeignId(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('reorder-foreign')->create();
        CommunityFactory::new()->withIdentifier('reorder-other')->create();

        $responseA = $this->jsonClient($admin)->request('POST', '/api/v1/communities/reorder-foreign/sections', ['json' => [
            'name' => 'Section A',
        ]]);
        self::assertResponseStatusCodeSame(201);
        $idA = $responseA->toArray()['id'];

        $responseForeign = $this->jsonClient($admin)->request('POST', '/api/v1/communities/reorder-other/sections', ['json' => [
            'name' => 'Foreign Section',
        ]]);
        self::assertResponseStatusCodeSame(201);
        $foreignId = $responseForeign->toArray()['id'];

        $this->jsonClient($admin)->request('PUT', '/api/v1/communities/reorder-foreign/sections/order', [
            'json' => ['sections' => [$foreignId]],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testNonAdminForbidden(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('reorder-forbidden')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);

        $responseA = $this->jsonClient($admin)->request('POST', '/api/v1/communities/reorder-forbidden/sections', ['json' => [
            'name' => 'Section',
        ]]);
        self::assertResponseStatusCodeSame(201);
        $idA = $responseA->toArray()['id'];

        $this->jsonClient($member)->request('PUT', '/api/v1/communities/reorder-forbidden/sections/order', [
            'json' => ['sections' => [$idA]],
        ]);
        self::assertResponseStatusCodeSame(403);
    }
}
