<?php

declare(strict_types=1);

namespace App\Service;

use App\Dto\EntityDtoInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use Symfony\Contracts\Service\Attribute\Required;

abstract class AbstractDoctrineService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected EntityManagerInterface $entityManager;

    #[Required]
    public function setEntityManager(EntityManagerInterface $entityManager): void
    {
        $this->entityManager = $entityManager;
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    protected function save(object $entity, ?EntityDtoInterface $dto = null): object
    {
        if (null !== $dto) {
            $dto->applyTo($entity);
        }

        return $this->saveEntity($entity);
    }

    protected function persist(object $entity): void
    {
        $this->entityManager->persist($entity);
    }

    protected function flush(): void
    {
        $this->entityManager->flush();
    }

    protected function removeAndFlush(object $entity): void
    {
        $this->entityManager->remove($entity);
        $this->entityManager->flush();
    }

    protected function clear(): void
    {
        $this->entityManager->clear();
    }

    protected function refresh(object $entity): void
    {
        $this->entityManager->refresh($entity);
    }

    protected function transactional(\Closure $fn): mixed
    {
        return $this->entityManager->wrapInTransaction($fn);
    }

    /**
     * @template T of object
     *
     * @param T $entity
     *
     * @return T
     */
    private function saveEntity(object $entity): object
    {
        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        return $entity;
    }
}
