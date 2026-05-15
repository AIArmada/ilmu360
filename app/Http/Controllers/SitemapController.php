<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Institution;
use App\Models\Speaker;
use Illuminate\Http\Response;

class SitemapController extends Controller
{
    public function index(): Response
    {
        $content = '<?xml version="1.0" encoding="UTF-8"?>';
        $content .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
        $content .= '<sitemap><loc>'.url('/sitemap-events.xml').'</loc></sitemap>';
        $content .= '<sitemap><loc>'.url('/sitemap-institutions.xml').'</loc></sitemap>';
        $content .= '<sitemap><loc>'.url('/sitemap-speakers.xml').'</loc></sitemap>';
        $content .= '</sitemapindex>';

        return response($content)
            ->header('Content-Type', 'application/xml');
    }

    public function events(): Response
    {
        $events = Event::query()
            ->active()
            ->orderBy('updated_at', 'desc')
            ->take(50000)
            ->get(['slug', 'updated_at']);

        $content = '<?xml version="1.0" encoding="UTF-8"?>';
        $content .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($events as $event) {
            $content .= '<url>';
            $content .= '<loc>'.route('events.show', $event->slug).'</loc>';
            $content .= '<lastmod>'.$event->updated_at->toW3cString().'</lastmod>';
            $content .= '<changefreq>weekly</changefreq>';
            $content .= '<priority>0.8</priority>';
            $content .= '</url>';
        }

        $content .= '</urlset>';

        return response($content)
            ->header('Content-Type', 'application/xml');
    }

    public function institutions(): Response
    {
        $institutions = Institution::query()
            ->orderBy('updated_at', 'desc')
            ->take(50000)
            ->get(['slug', 'updated_at']);

        $content = '<?xml version="1.0" encoding="UTF-8"?>';
        $content .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($institutions as $institution) {
            $content .= '<url>';
            $content .= '<loc>'.route('institutions.show', $institution->slug).'</loc>';
            $content .= '<lastmod>'.$institution->updated_at->toW3cString().'</lastmod>';
            $content .= '<changefreq>monthly</changefreq>';
            $content .= '<priority>0.6</priority>';
            $content .= '</url>';
        }

        $content .= '</urlset>';

        return response($content)
            ->header('Content-Type', 'application/xml');
    }

    public function speakers(): Response
    {
        $speakers = Speaker::query()
            ->orderBy('updated_at', 'desc')
            ->take(50000)
            ->get(['slug', 'updated_at']);

        $content = '<?xml version="1.0" encoding="UTF-8"?>';
        $content .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';

        foreach ($speakers as $speaker) {
            $content .= '<url>';
            $content .= '<loc>'.route('speakers.show', $speaker->slug).'</loc>';
            $content .= '<lastmod>'.$speaker->updated_at->toW3cString().'</lastmod>';
            $content .= '<changefreq>monthly</changefreq>';
            $content .= '<priority>0.5</priority>';
            $content .= '</url>';
        }

        $content .= '</urlset>';

        return response($content)
            ->header('Content-Type', 'application/xml');
    }
}
