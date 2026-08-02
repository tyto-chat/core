<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase as BaseApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Entity\Community;
use App\Entity\User;
use App\Enum\ApiKey\ApiKeyScope;
use App\Tests\Factory\ApiKeyFactory;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

abstract class ApiTestCase extends BaseApiTestCase
{
    protected static ?bool $alwaysBootKernel = false;

    protected function jsonClient(?User $user = null, ?string $locale = null): Client
    {
        $headers = [
            'Content-Type' => 'application/ld+json',
            'Accept' => 'application/ld+json',
        ];

        if (null !== $user) {
            $jwt = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
            $headers['Authorization'] = 'Bearer '.$jwt;
        }

        if (null !== $locale) {
            $headers['Accept-Language'] = $locale;
        }

        return static::createClient(defaultOptions: ['headers' => $headers]);
    }

    /**
     * Like jsonClient() but uses plain application/json — for endpoints that do not support JSON-LD.
     */
    protected function plainJsonClient(?User $user = null): Client
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if (null !== $user) {
            $jwt = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
            $headers['Authorization'] = 'Bearer '.$jwt;
        }

        return static::createClient(defaultOptions: ['headers' => $headers]);
    }

    protected function apiKeyClient(User $user): Client
    {
        $issued = ApiKeyFactory::createWithToken($user, ['scopes' => array_map(
            static fn (ApiKeyScope $scope): string => $scope->value,
            array_filter(ApiKeyScope::cases(), static fn (ApiKeyScope $scope): bool => ApiKeyScope::Admin !== $scope),
        )]);

        return static::createClient(defaultOptions: [
            'headers' => [
                'Authorization' => 'Bearer '.$issued['plainToken'],
                'Accept' => 'application/ld+json',
            ],
        ]);
    }

    protected function uploadClient(?User $user = null): Client
    {
        $headers = [
            'Accept' => 'application/ld+json',
            'Content-Type' => 'multipart/form-data',
        ];

        if (null !== $user) {
            $jwt = static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
            $headers['Authorization'] = 'Bearer '.$jwt;
        }

        return static::createClient(defaultOptions: ['headers' => $headers]);
    }

    protected function communityIri(Community $community): string
    {
        return '/api/v1/communities/'.$community->getIdentifier();
    }

    protected function channelIri(Community $community, \App\Entity\Channel $channel): string
    {
        return '/api/v1/communities/'.$community->getIdentifier().'/channels/'.$channel->getIdentifier();
    }
}
