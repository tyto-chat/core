<?php

declare(strict_types=1);

namespace App\Service\HttpCache;

use Symfony\Contracts\Service\Attribute\Required;

trait PurgesHttpCacheTrait
{
    private ?CachePurgerInterface $cachePurger = null;

    #[Required]
    public function setCachePurger(CachePurgerInterface $cachePurger): void
    {
        $this->cachePurger = $cachePurger;
    }
}
