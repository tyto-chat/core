<?php

declare(strict_types=1);

namespace App\Tests\Trait;

use App\Entity\Setting;
use App\Settings\Settings;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Seeds a `validateEmails=false` override row so functional tests that exercise
 * registration / challenge flows skip the email-validation gate (the registry
 * default is true). Call seedEmailValidationDisabled() in the test's setUp,
 * after the DAMA transaction has begun.
 */
trait DisablesEmailValidationTrait
{
    protected function seedEmailValidationDisabled(): void
    {
        $em = static::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $row = (new Setting(Settings::validateEmails()->key))->setValue(false);
        $em->persist($row);
        $em->flush();
    }
}
