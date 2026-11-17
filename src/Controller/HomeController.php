<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\ArticleRequest;
use App\Form\ArticleType;
use App\Repository\ArticleRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The home page: a link field, then the library.
 */
final class HomeController extends AbstractController
{
    public const string LIBRARY_FRAME = 'library';

    #[Route('/', name: 'home', methods: ['GET'])]
    public function index(Request $request, ArticleRepository $articles): Response
    {
        // The library refreshes itself as a Turbo Frame while articles are being prepared.
        if (self::LIBRARY_FRAME === $request->headers->get('Turbo-Frame')) {
            return $this->render('home/_library_frame.html.twig', ['articles' => $articles->latest(), 'frame_response' => true]);
        }

        $form = $this->createForm(ArticleType::class, new ArticleRequest(), [
            'action' => $this->generateUrl('article_create'),
        ]);

        return $this->render('home/index.html.twig', [
            'form' => $form,
            'articles' => $articles->latest(),
        ]);
    }
}
