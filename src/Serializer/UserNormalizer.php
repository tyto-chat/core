<?php

declare(strict_types=1);

namespace App\Serializer;

use ApiPlatform\Metadata\HttpOperation;
use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class UserNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED = 'USER_NORMALIZER_ALREADY_CALLED';

    public function __construct(
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @param array<mixed> $context
     *
     * @return array<string, mixed>|string|int|float|bool|\ArrayObject<int|string, mixed>|null
     */
    #[\Override]
    public function normalize(
        mixed $object,
        ?string $format = null,
        array $context = [],
    ): array|string|int|float|bool|\ArrayObject|null {
        $context[self::ALREADY_CALLED] = true;

        /** @var User $object */
        $current = $this->security->getUser();
        $isSelf = $current instanceof User && $current->getId() === $object->getId();

        // PII group must never enter shared fan-outs: HTTP-cache-bucketed ops replay one payload to all viewers, and Mercure auto-publish serializes as the author (isSelf true, bare context without 'operation').
        if (($isSelf || $this->security->isGranted('ROLE_ADMIN'))
            && !$this->isCacheFlaggedOperation()
            && $this->isDirectApiResponse($context)
        ) {
            /** @var list<string> $groups */
            $groups = (array) ($context['groups'] ?? []);
            if (!\in_array('user:read:self', $groups, true)) {
                $groups[] = 'user:read:self';
            }
            $context['groups'] = $groups;
        }

        return $this->normalizer->normalize($object, $format, $context);
    }

    private function isCacheFlaggedOperation(): bool
    {
        $request = $this->requestStack->getMainRequest();
        if (null === $request) {
            return false;
        }

        $operation = $request->attributes->get('_api_operation');

        return $operation instanceof HttpOperation
            && false !== ($operation->getExtraProperties()['tyto_http_cache'] ?? false);
    }

    /**
     * @param array<mixed> $context
     */
    private function isDirectApiResponse(array $context): bool
    {
        return \array_key_exists('operation', $context);
    }

    /** @param array<mixed> $context */
    #[\Override]
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        if (isset($context[self::ALREADY_CALLED])) {
            return false;
        }

        return $data instanceof User;
    }

    #[\Override]
    public function getSupportedTypes(?string $format): array
    {
        return [User::class => false];
    }
}
