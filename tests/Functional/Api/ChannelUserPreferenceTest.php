<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Channel;
use App\Entity\User;
use App\Enum\Channel\ChannelNotificationLevel;
use App\Enum\Channel\ChannelPinState;
use App\Repository\ChannelUserPreferenceRepository;
use App\Service\Notification\ChannelUserPreferenceServiceInterface;
use App\Service\UserContextServiceInterface;
use App\Tests\Factory\ChannelFactory;
use App\Tests\Factory\CommunityFactory;
use App\Tests\Factory\CommunityMemberFactory;
use App\Tests\Factory\UserFactory;
use App\Tests\Functional\ApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

class ChannelUserPreferenceTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    private function repo(): ChannelUserPreferenceRepository
    {
        $repo = static::getContainer()->get(ChannelUserPreferenceRepository::class);
        \assert($repo instanceof ChannelUserPreferenceRepository);

        return $repo;
    }

    private function setPinAs(User $user, Channel $channel, ?ChannelPinState $state): void
    {
        $context = static::getContainer()->get(UserContextServiceInterface::class);
        \assert($context instanceof UserContextServiceInterface);
        $service = static::getContainer()->get(ChannelUserPreferenceServiceInterface::class);
        \assert($service instanceof ChannelUserPreferenceServiceInterface);

        $context->runAs($user, static fn () => $service->setPinState($channel, $state));
    }

    private function setLevelAs(User $user, Channel $channel, ?ChannelNotificationLevel $level): void
    {
        $context = static::getContainer()->get(UserContextServiceInterface::class);
        \assert($context instanceof UserContextServiceInterface);
        $service = static::getContainer()->get(ChannelUserPreferenceServiceInterface::class);
        \assert($service instanceof ChannelUserPreferenceServiceInterface);

        $context->runAs($user, static fn () => $service->setChannelLevel($channel, $level));
    }

    private function clearEntityManager(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->clear();
    }

    public function testFavoriteThenHiddenIsMutuallyExclusive(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cup1')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $this->setPinAs($user, $channel, ChannelPinState::Favorite);
        $this->setPinAs($user, $channel, ChannelPinState::Hidden);

        $this->clearEntityManager();
        $row = $this->repo()->findForUser($channel, $user);
        self::assertNotNull($row);
        self::assertSame(ChannelPinState::Hidden, $row->getPinState());
    }

    public function testClearingPinKeepsRowWhenLevelDeviates(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cup2')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $this->setLevelAs($user, $channel, ChannelNotificationLevel::All);
        $this->setPinAs($user, $channel, ChannelPinState::Favorite);
        $this->setPinAs($user, $channel, null);

        $this->clearEntityManager();
        $row = $this->repo()->findForUser($channel, $user);
        self::assertNotNull($row);
        self::assertSame(ChannelNotificationLevel::All, $row->getLevel());
        self::assertNull($row->getPinState());
    }

    public function testClearingPinRemovesRowWhenLevelDefault(): void
    {
        $user = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cup3')->create();
        CommunityMemberFactory::createForUserAndCommunity($user, $community);
        $channel = ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $this->setPinAs($user, $channel, ChannelPinState::Hidden);
        $this->setPinAs($user, $channel, null);

        $this->clearEntityManager();
        self::assertNull($this->repo()->findForUser($channel, $user));
    }

    public function testPinStateEndpointPersistsAndClears(): void
    {
        $caller = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cup-ep')->create();
        CommunityMemberFactory::createForUserAndCommunity($caller, $community);
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $client = $this->plainJsonClient($caller);
        $client->request('PUT', '/api/v1/communities/cup-ep/channels/general/pin-state', [
            'json' => ['pinState' => 'favorite'],
        ]);
        self::assertResponseIsSuccessful();
        self::assertSame('favorite', $client->getResponse()->toArray()['pinState']);

        $client->request('PUT', '/api/v1/communities/cup-ep/channels/general/pin-state', [
            'json' => ['pinState' => null],
        ]);
        self::assertResponseIsSuccessful();
        self::assertNull($client->getResponse()->toArray()['pinState']);
    }

    public function testPinStateEndpointDeniedForNonMemberOfPrivateCommunity(): void
    {
        $outsider = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cup-priv')->private()->create();
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $client = $this->plainJsonClient($outsider);
        $client->request('PUT', '/api/v1/communities/cup-priv/channels/general/pin-state', [
            'json' => ['pinState' => 'favorite'],
        ]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testPinStateEndpointRejectsInvalidValue(): void
    {
        $caller = UserFactory::createOne();
        $community = CommunityFactory::new()->withIdentifier('cup-bad')->create();
        CommunityMemberFactory::createForUserAndCommunity($caller, $community);
        ChannelFactory::new()->inCommunity($community)->with(['identifier' => 'general'])->create();

        $this->plainJsonClient($caller)->request('PUT', '/api/v1/communities/cup-bad/channels/general/pin-state', [
            'json' => ['pinState' => 'bogus'],
        ]);
        self::assertResponseStatusCodeSame(422);
    }
}
