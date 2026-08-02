<?php

declare(strict_types=1);

namespace App\Serializer;

use App\Entity\MediaObject;
use App\Service\MediaObject\MediaObjectServiceInterface;
use App\Service\MediaObject\SignedUrlServiceInterface;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class MediaObjectNormalizer implements NormalizerInterface
{
    private const ALREADY_CALLED = 'MEDIA_OBJECT_NORMALIZER_ALREADY_CALLED';

    public function __construct(
        #[Autowire(service: 'api_platform.jsonld.normalizer.item')]
        private readonly NormalizerInterface $normalizer,
        #[Autowire(service: 'api_platform.serializer.normalizer.item')]
        private readonly NormalizerInterface $jsonNormalizer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly SignedUrlServiceInterface $signedUrlService,
        private readonly SettingsServiceInterface $settings,
    ) {
    }

    public function normalize($object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $context[self::ALREADY_CALLED] = true;

        if ($object->filePath && null !== $object->type) {
            if ('avatar' === $object->type || 'logo' === $object->type) {
                $object->contentUrl = array_map(
                    function (string $filter) use ($object): string {
                        $relativePath = $filter.'/'.$object->filePath;

                        return $this->urlGenerator->generate(
                            'app_media_serve_variant',
                            [
                                'token' => $this->signedUrlService->sign($relativePath),
                                'filter' => $filter,
                                'filename' => $object->filePath,
                            ],
                            UrlGeneratorInterface::ABSOLUTE_URL,
                        );
                    },
                    MediaObjectServiceInterface::FILTERS[$object->type],
                );
            } elseif ('attachment' === $object->type) {
                $object->contentUrl = $this->urlGenerator->generate(
                    'app_media_serve',
                    [
                        'token' => $this->signedUrlService->signStable(
                            $object->filePath,
                            $this->settings->get(Settings::mediaTokenTtlSeconds()),
                        ),
                        'filename' => $object->filePath,
                    ],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                );
            } elseif ('community_emoji' === $object->type) {
                $relativePath = 'community_emoji/'.$object->filePath;
                $object->contentUrl = $this->urlGenerator->generate(
                    'app_media_serve_variant',
                    [
                        'token' => $this->signedUrlService->signStable($relativePath, $this->settings->get(Settings::communityEmojiTokenTtlSeconds())),
                        'filter' => 'community_emoji',
                        'filename' => $object->filePath,
                    ],
                    UrlGeneratorInterface::ABSOLUTE_URL,
                );
            } else {
                $object->contentUrl = null;
            }
        } else {
            $object->contentUrl = null;
        }

        // The jsonld item normalizer would wrap plain-JSON responses in JSON-LD envelopes.
        $inner = 'jsonld' === $format ? $this->normalizer : $this->jsonNormalizer;

        return $inner->normalize($object, $format, $context);
    }

    public function supportsNormalization($data, ?string $format = null, array $context = []): bool
    {
        if (isset($context[self::ALREADY_CALLED])) {
            return false;
        }

        return in_array($format, ['jsonld', 'json'], true) && $data instanceof MediaObject;
    }

    public function getSupportedTypes(?string $format): array
    {
        return in_array($format, ['jsonld', 'json'], true) ? [MediaObject::class => true] : [];
    }
}
