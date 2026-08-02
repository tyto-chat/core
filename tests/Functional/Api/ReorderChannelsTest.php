<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ReorderChannelsTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    public function testAdminReordersChannels(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('rchan-order')->create();

        $sectionResponse = $this->jsonClient($admin)->request('POST', '/api/v1/communities/rchan-order/sections', ['json' => [
            'name' => 'My Section',
        ]]);
        self::assertResponseStatusCodeSame(201);
        $sectionData = $sectionResponse->toArray();
        $sectionId = $sectionData['id'];
        $sectionIri = $sectionData['@id'];

        $responseA = $this->jsonClient($admin)->request('POST', '/api/v1/channels', ['json' => [
            'name' => 'Channel A',
            'type' => 'text',
            'community' => '/api/v1/communities/rchan-order',
            'section' => $sectionIri,
        ]]);
        self::assertResponseStatusCodeSame(201);
        $idA = $responseA->toArray()['id'];

        $responseB = $this->jsonClient($admin)->request('POST', '/api/v1/channels', ['json' => [
            'name' => 'Channel B',
            'type' => 'text',
            'community' => '/api/v1/communities/rchan-order',
            'section' => $sectionIri,
        ]]);
        self::assertResponseStatusCodeSame(201);
        $idB = $responseB->toArray()['id'];

        $responseC = $this->jsonClient($admin)->request('POST', '/api/v1/channels', ['json' => [
            'name' => 'Channel C',
            'type' => 'text',
            'community' => '/api/v1/communities/rchan-order',
            'section' => $sectionIri,
        ]]);
        self::assertResponseStatusCodeSame(201);
        $idC = $responseC->toArray()['id'];

        $this->jsonClient($admin)->request('PUT', '/api/v1/communities/rchan-order/sections/'.$sectionId.'/channels/order', [
            'json' => ['channels' => [$idB, $idC, $idA]],
        ]);
        self::assertResponseStatusCodeSame(204);

        $response = $this->jsonClient($admin)->request('GET', '/api/v1/communities/rchan-order');
        self::assertResponseStatusCodeSame(200);

        $data = $response->toArray();
        $sectionChannels = array_values(array_filter(
            $data['channels'],
            static fn (array $ch) => $ch['section']['id'] === $sectionId,
        ));
        $orderedIds = array_map(static fn (array $ch) => $ch['id'], $sectionChannels);
        self::assertSame([$idB, $idC, $idA], $orderedIds);
    }

    public function testRejectsChannelFromAnotherSection(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $community = CommunityFactory::new()->withIdentifier('rchan-foreign')->create();

        $sectionAResponse = $this->jsonClient($admin)->request('POST', '/api/v1/communities/rchan-foreign/sections', ['json' => [
            'name' => 'Section A',
        ]]);
        self::assertResponseStatusCodeSame(201);
        $sectionAData = $sectionAResponse->toArray();
        $sectionAId = $sectionAData['id'];
        $sectionAIri = $sectionAData['@id'];

        $sectionBResponse = $this->jsonClient($admin)->request('POST', '/api/v1/communities/rchan-foreign/sections', ['json' => [
            'name' => 'Section B',
        ]]);
        self::assertResponseStatusCodeSame(201);
        $sectionBIri = $sectionBResponse->toArray()['@id'];

        $responseA = $this->jsonClient($admin)->request('POST', '/api/v1/channels', ['json' => [
            'name' => 'Channel A',
            'type' => 'text',
            'community' => '/api/v1/communities/rchan-foreign',
            'section' => $sectionAIri,
        ]]);
        self::assertResponseStatusCodeSame(201);
        $idA = $responseA->toArray()['id'];

        $responseB = $this->jsonClient($admin)->request('POST', '/api/v1/channels', ['json' => [
            'name' => 'Channel B',
            'type' => 'text',
            'community' => '/api/v1/communities/rchan-foreign',
            'section' => $sectionBIri,
        ]]);
        self::assertResponseStatusCodeSame(201);
        $idForeign = $responseB->toArray()['id'];

        $this->jsonClient($admin)->request('PUT', '/api/v1/communities/rchan-foreign/sections/'.$sectionAId.'/channels/order', [
            'json' => ['channels' => [$idA, $idForeign]],
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testNonAdminForbidden(): void
    {
        $admin = UserFactory::new()->admin()->create();
        $member = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('rchan-forbidden')->create();
        CommunityMemberFactory::createForUserAndCommunity($member, $community);

        $sectionResponse = $this->jsonClient($admin)->request('POST', '/api/v1/communities/rchan-forbidden/sections', ['json' => [
            'name' => 'Section',
        ]]);
        self::assertResponseStatusCodeSame(201);
        $sectionData = $sectionResponse->toArray();
        $sectionId = $sectionData['id'];
        $sectionIri = $sectionData['@id'];

        $responseA = $this->jsonClient($admin)->request('POST', '/api/v1/channels', ['json' => [
            'name' => 'Channel A',
            'type' => 'text',
            'community' => '/api/v1/communities/rchan-forbidden',
            'section' => $sectionIri,
        ]]);
        self::assertResponseStatusCodeSame(201);
        $idA = $responseA->toArray()['id'];

        $this->jsonClient($member)->request('PUT', '/api/v1/communities/rchan-forbidden/sections/'.$sectionId.'/channels/order', [
            'json' => ['channels' => [$idA]],
        ]);
        self::assertResponseStatusCodeSame(403);
    }
}
