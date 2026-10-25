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
        #[Autowire('%kernel.project_dir%/var/http-cache.on')] private string $flagFile,
    ) {
    }

    #[\Override]
    public function isOn(): bool
    {
        if (is_file($this->trippedFile())) {
            if (!$this->holdExpired()) {
                return false;
            }
            $this->sync();
        }

        return is_file($this->flagFile);
    }

    #[\Override]
    public function trip(int $holdSeconds): void
    {
        $this->locked(function () use ($holdSeconds): void {
            $this->filesystem->dumpFile($this->trippedFile(), (string) (time() + max(1, $holdSeconds)));
            $this->filesystem->remove($this->flagFile);
        });
    }

    #[\Override]
    public function sync(): bool
    {
        return $this->locked(function (): bool {
            $on = $this->settings->get(Settings::httpCachePageTtlSeconds()) > 0
                || $this->settings->get(Settings::httpCachePresenceTtlSeconds()) > 0;

            if (is_file($this->trippedFile())) {
                if (!$this->holdExpired()) {
                    $this->filesystem->remove($this->flagFile);

                    return false;
                }
                $this->filesystem->remove($this->trippedFile());
            }

            if ($on) {
                $this->filesystem->touch($this->flagFile);
            } else {
                $this->filesystem->remove($this->flagFile);
            }

            return $on;
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

    private function trippedFile(): string
    {
        return $this->flagFile.'.tripped';
    }

    private function holdExpired(): bool
    {
        return (int) @file_get_contents($this->trippedFile()) <= time();
    }
}
