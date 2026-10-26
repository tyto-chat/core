<?php

declare(strict_types=1);

namespace App\Service\HttpCache;

use App\Service\Settings\SettingsServiceInterface;
use App\Settings\Settings;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

final readonly class HttpCacheSwitch implements HttpCacheSwitchInterface
{
    public function __construct(
        private SettingsServiceInterface $settings,
        private Filesystem $filesystem,
        #[Autowire('%kernel.project_dir%/var/http-cache/http-cache.on')] private string $flagFile,
    ) {
    }

    #[\Override]
    public function isOn(): bool
    {
        return is_file($this->flagFile);
    }

    #[\Override]
    public function isPresenceOn(): bool
    {
        return is_file($this->presenceFlagFile());
    }

    #[\Override]
    public function sync(): bool
    {
        $pages = $this->settings->get(Settings::httpCachePageTtlSeconds()) > 0;
        $presence = $this->settings->get(Settings::httpCachePresenceTtlSeconds()) > 0;

        $this->filesystem->mkdir(\dirname($this->flagFile));
        $this->mirror($this->flagFile, $pages);
        $this->mirror($this->presenceFlagFile(), $presence);

        return $pages || $presence;
    }

    private function mirror(string $flagFile, bool $on): void
    {
        if ($on) {
            $this->filesystem->touch($flagFile);
        } else {
            $this->filesystem->remove($flagFile);
        }
    }

    private function presenceFlagFile(): string
    {
        return \dirname($this->flagFile).'/http-cache-presence.on';
    }
}
