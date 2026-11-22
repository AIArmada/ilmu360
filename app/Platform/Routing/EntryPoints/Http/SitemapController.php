<?php

declare(strict_types=1);

namespace App\Platform\Routing\EntryPoints\Http;

use App\Http\Controllers\Controller;
use App\Platform\Cache\VersionedCache;
use App\Platform\Routing\SitemapUrlProvider;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    private const int CACHE_TTL_SECONDS = 1800;

    public function index(): Response
    {
        $content = app(VersionedCache::class)->remember('sitemap:version:v1', 'sitemap:index:xml', self::CACHE_TTL_SECONDS, function (): string {
            $xml = '<?xml version="1.0" encoding="UTF-8"?>';
            $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            $xml .= '<sitemap><loc>'.$this->escapeXml(route('sitemap.events')).'</loc></sitemap>';
            $xml .= '<sitemap><loc>'.$this->escapeXml(route('sitemap.institutions')).'</loc></sitemap>';
            $xml .= '<sitemap><loc>'.$this->escapeXml(route('sitemap.persons')).'</loc></sitemap>';
            $xml .= '</sitemapindex>';

            return $xml;
        });

        return response($content)
            ->header('Content-Type', 'application/xml; charset=UTF-8');
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

        $content = app(VersionedCache::class)->remember("sitemap:{$name}:version:v1", "sitemap:{$name}:xml", self::CACHE_TTL_SECONDS, function () use ($provider): string {
            $xml = '<?xml version="1.0" encoding="UTF-8"?>';
            $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

            foreach ($provider->urls() as $url) {
                $xml .= '<url>';
                $xml .= '<loc>'.$this->escapeXml((string) $url['loc']).'</loc>';
                $xml .= '<lastmod>'.$this->escapeXml((string) $url['lastmod']).'</lastmod>';
                $xml .= '<changefreq>'.$this->escapeXml((string) $url['changefreq']).'</changefreq>';
                $xml .= '<priority>'.$this->escapeXml((string) $url['priority']).'</priority>';
                $xml .= '</url>';
            }

            $xml .= '</urlset>';

            return $xml;
        });

        return response($content)
            ->header('Content-Type', 'application/xml; charset=UTF-8');
    }

    private function escapeXml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
