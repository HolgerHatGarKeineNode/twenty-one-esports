<?php

namespace App\Http\Controllers;

use App\Support\Seo\LocalizedUrls;
use App\Support\Seo\Sitemap;
use Illuminate\Http\Response;
use XMLWriter;

/**
 * /sitemap.xml, the index, and its files /sitemaps/{section}-{n}.xml (P14),
 * from App\Support\Seo\Sitemap. No session: the routes run outside the web
 * middleware group, like /robots.txt.
 */
class SitemapController extends Controller
{
    public function index(Sitemap $sitemap): Response
    {
        $xml = $this->document();
        $xml->startElement('sitemapindex');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        foreach ($sitemap->files() as $section => $count) {
            for ($file = 1; $file <= $count; $file++) {
                $xml->startElement('sitemap');
                $xml->writeElement('loc', route('sitemap.section', ['section' => $section, 'file' => $file]));
                $xml->endElement();
            }
        }

        $xml->endElement();

        return $this->respond($xml);
    }

    public function show(Sitemap $sitemap, string $section, int $file): Response
    {
        $entries = $sitemap->entries($section, $file);
        abort_if($entries === null, 404);

        $xml = $this->document();
        $xml->startElement('urlset');
        $xml->writeAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $xml->writeAttribute('xmlns:xhtml', 'http://www.w3.org/1999/xhtml');

        foreach ($entries as $entry) {
            $alternates = LocalizedUrls::alternates($entry['url']);

            // One <url> per language, each naming all of them (Google's hreflang-in-sitemap form).
            foreach (LocalizedUrls::locales() as $locale) {
                $xml->startElement('url');
                $xml->writeElement('loc', $alternates[$locale]);

                if ($entry['lastmod'] !== null) {
                    $xml->writeElement('lastmod', $entry['lastmod']->toAtomString());
                }

                foreach ($alternates as $hreflang => $href) {
                    $xml->startElement('xhtml:link');
                    $xml->writeAttribute('rel', 'alternate');
                    $xml->writeAttribute('hreflang', $hreflang);
                    $xml->writeAttribute('href', $href);
                    $xml->endElement();
                }

                $xml->endElement();
            }
        }

        $xml->endElement();

        return $this->respond($xml);
    }

    private function document(): XMLWriter
    {
        $xml = new XMLWriter;
        $xml->openMemory();
        $xml->startDocument('1.0', 'UTF-8');

        return $xml;
    }

    private function respond(XMLWriter $xml): Response
    {
        $xml->endDocument();

        return response($xml->outputMemory(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
