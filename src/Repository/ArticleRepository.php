<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Article;
use App\Enum\ArticleStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<Article>
 */
class ArticleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Article::class);
    }

    public function get(Uuid $id): ?Article
    {
        return $this->find($id);
    }

    /**
     * @return list<Article> the most recent first
     */
    public function latest(int $limit = 50): array
    {
        /** @var list<Article> */
        return $this->createQueryBuilder('a')
            ->orderBy('a.createdAt', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Article> the articles ready to be listened to from start to end, the most recent first
     */
    public function ready(int $limit = 100): array
    {
        /** @var list<Article> */
        return $this->createQueryBuilder('a')
            ->andWhere('a.status = :status')
            ->setParameter('status', ArticleStatus::Ready)
            ->orderBy('a.readyAt', \SortDirection::Descending)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function save(Article $article): void
    {
        $this->getEntityManager()->persist($article);
        $this->getEntityManager()->flush();
    }

    public function remove(Article $article): void
    {
        $this->getEntityManager()->remove($article);
        $this->getEntityManager()->flush();
    }
}
