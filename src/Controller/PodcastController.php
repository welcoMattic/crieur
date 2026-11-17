<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Article;
use App\Enum\ArticleStatus;
use App\Library\EpisodeBuilder;
use App\Library\FeedToken;
use App\Repository\ArticleRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The library as a private podcast: every ready article becomes an episode.
 */
final class PodcastController extends AbstractController
{
    public function __construct(
        private readonly FeedToken $feedToken,
        private readonly EpisodeBuilder $episodes,
    ) {
    }

    #[Route('/feed/{token}.xml', name: 'podcast_feed', methods: ['GET'])]
    public function feed(string $token, ArticleRepository $articles): Response
    {
        if (!$this->feedToken->matches($token)) {
            throw new NotFoundHttpException();
        }

        $items = [];
        foreach ($articles->ready() as $article) {
            $episode = $this->episodes->build($article);
            $items[] = ['article' => $article, 'episode' => $episode, 'size' => (int) filesize($episode['path'])];
        }

        $response = $this->render('podcast/feed.xml.twig', ['items' => $items, 'token' => $token]);
        $response->headers->set('Content-Type', 'application/rss+xml; charset=UTF-8');

        return $response;
    }

    #[Route('/feed/{token}/{id}.{_format}', name: 'podcast_episode', requirements: ['_format' => 'm4a|wav'], methods: ['GET', 'HEAD'])]
    public function episode(string $token, #[MapEntity(id: 'id')] Article $article): BinaryFileResponse
    {
        if (!$this->feedToken->matches($token) || ArticleStatus::Ready !== $article->getStatus()) {
            throw new NotFoundHttpException();
        }

        return $this->download($article);
    }

    #[Route('/articles/{id}/episode', name: 'article_episode', methods: ['GET', 'HEAD'])]
    public function download(#[MapEntity(id: 'id')] Article $article): BinaryFileResponse
    {
        if (ArticleStatus::Ready !== $article->getStatus()) {
            throw new NotFoundHttpException('The episode exists once the whole article is synthesized.');
        }

        $episode = $this->episodes->build($article);
        $extension = 'audio/mp4' === $episode['mimeType'] ? 'm4a' : 'wav';
        $response = new BinaryFileResponse($episode['path'], headers: ['Content-Type' => $episode['mimeType']], autoEtag: true);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, \sprintf('crieur-%s.%s', $article->getId()->toBase58(), $extension));

        return $response;
    }
}
