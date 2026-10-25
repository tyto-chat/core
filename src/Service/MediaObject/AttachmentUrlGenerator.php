<?php

declare(strict_types=1);

namespace App\Service\MediaObject;

use App\Entity\MediaObject;
use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class AttachmentUrlGenerator implements AttachmentUrlGeneratorInterface
{
    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private SignedUrlServiceInterface $signedUrlService,
        private SettingsServiceInterface $settings,
        #[Autowire('%kernel.project_dir%/var/media')] private string $mediaDir,
    ) {
    }

    #[\Override]
    public function contentUrl(MediaObject $attachment): ?string
    {
        if (null === $attachment->filePath) {
            return null;
        }

        return $this->urlGenerator->generate(
            'app_media_serve',
            [
                'token' => $this->signedUrlService->signStable($attachment->filePath, $this->settings->get(Settings::mediaTokenTtlSeconds())),
                'filename' => $attachment->filePath,
            ],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }

    #[\Override]
    public function thumbnailUrl(MediaObject $attachment): ?string
    {
        if (null === $attachment->filePath) {
            return null;
        }

        $filter = MediaObjectServiceInterface::FILTERS['attachment'][0];
        $relativePath = $filter.'/'.$attachment->filePath;
        if (!is_file($this->mediaDir.'/'.$relativePath)) {
            return null;
        }

        return $this->urlGenerator->generate(
            'app_media_serve_variant',
            [
                'token' => $this->signedUrlService->signStable($relativePath, $this->settings->get(Settings::mediaTokenTtlSeconds())),
                'filter' => $filter,
                'filename' => $attachment->filePath,
            ],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
