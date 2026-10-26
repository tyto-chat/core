<?php

declare(strict_types=1);

namespace App\Service\MediaObject;

use App\Entity\MediaObject;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class AvatarUrlResolver implements AvatarUrlResolverInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private SignedUrlServiceInterface $signedUrlService,
    ) {
    }

    #[\Override]
    public function avatarUrl(?MediaObject $avatar): ?string
    {
        if (null === $avatar || null === $avatar->filePath || '' === $avatar->filePath) {
            return null;
        }

        $filter = MediaObjectServiceInterface::FILTERS['avatar']['md'];
        $relativePath = $filter.'/'.$avatar->filePath;

        return $this->urlGenerator->generate(
            'app_media_serve_variant',
            [
                'token' => $this->signedUrlService->sign($relativePath),
                'filter' => $filter,
                'filename' => $avatar->filePath,
            ],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
