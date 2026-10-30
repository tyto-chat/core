<?php

declare(strict_types=1);

namespace App\Storage;

use App\Entity\MediaObject;
use App\Service\MediaObject\MediaObjectServiceInterface;
use Vich\UploaderBundle\Mapping\PropertyMapping;
use Vich\UploaderBundle\Naming\NamerInterface;
use Vich\UploaderBundle\Naming\SmartUniqueNamer;

/** @implements NamerInterface<MediaObject> */
final readonly class MediaObjectNamer implements NamerInterface
{
    public function __construct(private SmartUniqueNamer $smartUniqueNamer)
    {
    }

    /** @param MediaObject|array<mixed> $object */
    public function name(object|array $object, PropertyMapping $mapping): string
    {
        if ($object instanceof MediaObject && MediaObjectServiceInterface::ENCRYPTED_ATTACHMENT === $object->type) {
            return bin2hex(random_bytes(16)).'.bin';
        }

        return $this->smartUniqueNamer->name($object, $mapping);
    }
}
