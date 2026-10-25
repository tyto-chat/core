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
        return $this->flagActive($this->flagFile);
    }

    #[\Override]
    public function isPresenceOn(): bool
    {
        return $this->flagActive($this->presenceFlagFile());
    }

    #[\Override]
    public function trip(int $holdSeconds): void
    {
        $this->locked(function () use ($holdSeconds): void {
            $this->filesystem->dumpFile($this->trippedFile(), (string) (time() + max(1, $holdSeconds)));
            $this->filesystem->remove([$this->flagFile, $this->presenceFlagFile()]);
        });
    }

    #[\Override]
    public function sync(): bool
    {
        return $this->locked(function (): bool {
            $pages = $this->settings->get(Settings::httpCachePageTtlSeconds()) > 0;
            $presence = $this->settings->get(Settings::httpCachePresenceTtlSeconds()) > 0;

            if (is_file($this->trippedFile())) {
                if (!$this->holdExpired()) {
                    $this->filesystem->remove([$this->flagFile, $this->presenceFlagFile()]);

                    return false;
                }
                $this->filesystem->remove($this->trippedFile());
            }

            $this->mirror($this->flagFile, $pages);
            $this->mirror($this->presenceFlagFile(), $presence);

            return $pages || $presence;
        });
    }

    /**
     * @template T
     *
     * @param callable(): T $action
     *
     * @return T
     */
    private function locked(callable $action): mixed
    {
        $this->filesystem->mkdir(\dirname($this->flagFile));
        $handle = fopen($this->flagFile.'.lock', 'c');
        if (false === $handle) {
            return $action();
        }

        try {
            flock($handle, LOCK_EX);

            return $action();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function flagActive(string $flagFile): bool
    {
        if (is_file($this->trippedFile())) {
            if (!$this->holdExpired()) {
                return false;
            }
            $this->sync();
        }

        return is_file($flagFile);
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
        return dirname($this->flagFile).'/http-cache-presence.on';
    }

    private function trippedFile(): string
    {
        return $this->flagFile.'.tripped';
    }

    private function holdExpired(): bool
    {
        return (int) @file_get_contents($this->trippedFile()) <= time();
    }
}
