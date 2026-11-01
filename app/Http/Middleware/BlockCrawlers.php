<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Panel jest prywatny: blokuje znane boty (wyszukiwarki i AI) i dodaje do każdej odpowiedzi
 * nagłówki zakazujące indeksowania, archiwizacji i wykorzystania treści przez AI.
 */
class BlockCrawlers
{
    /** Fragmenty User-Agent (małymi literami). */
    private const BOTS = [
        // AI
        'gptbot', 'chatgpt-user', 'oai-searchbot', 'claudebot', 'claude-user', 'claude-searchbot', 'claude-web', 'anthropic-ai',
        'google-extended', 'googleother', 'google-cloudvertexbot', 'applebot-extended', 'perplexitybot', 'perplexity-user',
        'ccbot', 'bytespider', 'meta-externalagent', 'meta-externalfetcher', 'facebookbot', 'amazonbot', 'cohere-ai',
        'cohere-training-data-crawler', 'diffbot', 'youbot', 'mistralai-user', 'duckassistbot', 'imagesiftbot', 'omgilibot', 'timpibot',
        // wyszukiwarki i SEO
        'googlebot', 'bingbot', 'yandex', 'baiduspider', 'duckduckbot', 'applebot', 'petalbot', 'seznambot', 'ahrefsbot',
        'semrushbot', 'mj12bot', 'dotbot', 'dataforseobot', 'blexbot',
    ];

    public const ROBOTS_HEADER = 'noindex, nofollow, noarchive, nosnippet, noimageindex, notranslate, noai, noimageai';

    public function handle(Request $request, Closure $next): Response
    {
        $ua = strtolower((string) $request->userAgent());

        // robots.txt zawsze dostępny – to w nim boty czytają zakaz
        if ($ua !== '' && ! $request->is('robots.txt') && $this->isBot($ua)) {
            return response('Dostęp zablokowany.', 403, ['X-Robots-Tag' => self::ROBOTS_HEADER]);
        }

        $response = $next($request);
        $response->headers->set('X-Robots-Tag', self::ROBOTS_HEADER);
        // linki do raportów zawierają tajny token – nie przekazujemy adresu innym stronom
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    private function isBot(string $ua): bool
    {
        foreach (self::BOTS as $bot) {
            if (str_contains($ua, $bot)) {
                return true;
            }
        }

        return false;
    }
}
