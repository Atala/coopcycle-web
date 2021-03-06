<?php

namespace AppBundle\Controller;

use AppBundle\Entity\Restaurant;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class SitemapController extends Controller
{
    /**
     * @Route("/sitemap.xml", name="sitemap")
     */
    public function indexAction()
    {
        $restaurants = $this->getDoctrine()
            ->getRepository(Restaurant::class)
            ->findBy(['enabled' => true]);

        $slugify = $this->get('slugify');

        $locales = ['en', 'es', 'de', 'fr'];
        $locale = $this->getParameter('locale');
        $otherLocales = array_diff($locales, [$locale]);

        $urls = [];
        foreach ($restaurants as $restaurant) {
            $url = [
                'loc' => $this->generateUrl('restaurant', [
                    'id' => $restaurant->getId(),
                    'slug' => $slugify->slugify($restaurant->getName()),
                    '_locale' => $locale
                ], UrlGeneratorInterface::ABSOLUTE_URL)
            ];

            foreach ($otherLocales as $otherLocale) {
                $alternateUrl = $this->generateUrl('restaurant', [
                    'id' => $restaurant->getId(),
                    'slug' => $slugify->slugify($restaurant->getName()),
                    '_locale' => $otherLocale
                ], UrlGeneratorInterface::ABSOLUTE_URL);

                $url['alternate_urls'][$otherLocale] = $alternateUrl;
            }

            $urls[] = $url;
        }

        $response = new Response($this->renderView('@App/index/sitemap.xml.twig', [
            'urls' => $urls,
        ]));
        $response->headers->set('Content-Type', 'text/xml');

        return $response;
    }
}
