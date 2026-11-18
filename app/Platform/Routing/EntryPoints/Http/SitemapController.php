<?php

declare(strict_types=1);

namespace App\Platform\Routing\EntryPoints\Http;

use App\Http\Controllers\Controller;
use App\Platform\Routing\SitemapUrlProvider;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function index(): Response
    {
        $content = '<?xml version="1.0" encoding="UTF-8"?>';
        $content .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        $content .= '<sitemap><loc>'.route('sitemap.events').'</loc></sitemap>';
        $content .= '<sitemap><loc>'.route('sitemap.institutions').'</loc></sitemap>';
        $content .= '<sitemap><loc>'.route('sitemap.persons').'</loc></sitemap>';
        $content .= '</sitemapindex>';

        return response($content)
            ->header('Content-Type', 'application/xml');
    }

    public function events(): Response
    {
        return $this->render('events');
    }

    public function institutions(): Response
    {
        return $this->render('institutions');
    }

    public function persons(): Response
    {
        return $this->render('persons');
    }

    private function render(string $name): Response
    {
        /** @var iterable<SitemapUrlProvider> $providers */
        $providers = app()->tagged('sitemap.urls');
        $provider = null;

        foreach ($providers as $candidate) {
            if ($candidate->name() === $name) {
                $provider = $candidate;

                break;
            }
        }

        abort_unless($provider instanceof SitemapUrlProvider, 404);

        $content = '<?xml version="1.0" encoding="UTF-8"?>';
        $content .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($provider->urls() as $url) {
            $content .= '<url>';
            $content .= '<loc>'.$url['loc'].'</loc>';
            $content .= '<lastmod>'.$url['lastmod'].'</lastmod>';
            $content .= '<changefreq>'.$url['changefreq'].'</changefreq>';
            $content .= '<priority>'.$url['priority'].'</priority>';
            $content .= '</url>';
        }

        $content .= '</urlset>';

        return response($content)
            ->header('Content-Type', 'application/xml');
    }
}
