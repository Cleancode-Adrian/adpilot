<?php

namespace Tests\Feature;

use App\Services\SiteAudit\SiteAuditor;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Audyt strony: llms.txt i dostęp robotów AI w robots.txt. */
class AiVisibilityAuditTest extends TestCase
{
    private function fake(?string $robots, ?string $llms, array $extra = []): void
    {
        $html = '<!doctype html><html lang="pl"><head><title>Firma – strony internetowe</title></head><body><h1>Firma</h1></body></html>';
        Http::fake(function (Request $r) use ($robots, $llms, $html, $extra) {
            $u = $r->url();
            foreach ($extra as $url => $status) {
                if ($u === $url) {
                    return Http::response('', $status);
                }
            }

            return match (true) {
                $u === 'https://firma.example/robots.txt' && $robots !== null => Http::response($robots, 200, ['Content-Type' => 'text/plain']),
                $u === 'https://firma.example/llms.txt' && $llms !== null => Http::response($llms, 200, ['Content-Type' => 'text/plain; charset=utf-8']),
                $u === 'https://firma.example/' => Http::response($html, 200, ['Content-Type' => 'text/html']),
                str_starts_with($u, 'https://firma.example/oferta') => Http::response($html, 200, ['Content-Type' => 'text/html']),
                default => Http::response('', 404),
            };
        });
    }

    private function issues(): Collection
    {
        return collect(app(SiteAuditor::class)->crawl('https://firma.example', 3)['issues'])->keyBy('code');
    }

    public function test_missing_llms_txt_is_low_issue(): void
    {
        $this->fake("User-agent: *\nDisallow: /wp-admin/", null);
        $issues = $this->issues();

        $this->assertSame('low', $issues['no_llms_txt']['severity']);
        $this->assertFalse($issues->has('ai_search_blocked'));
    }

    public function test_valid_llms_txt_passes(): void
    {
        $this->fake(null, "# Firma\n\n> Strony internetowe dla małych firm.\n\n## Oferta\n\n- [Strony www](https://firma.example/oferta): projekt i wdrożenie\n");
        $issues = $this->issues();

        $this->assertFalse($issues->has('no_llms_txt'));
        $this->assertFalse($issues->has('llms_txt_invalid'));
        $this->assertFalse($issues->has('llms_txt_broken_links'));
    }

    public function test_invalid_llms_txt_and_broken_links(): void
    {
        $this->fake(null, "Firma\n\n- [Usunięta](/stara-strona)\n- [Oferta](https://firma.example/oferta)\n");
        $issues = $this->issues();

        $this->assertSame('medium', $issues['llms_txt_invalid']['severity']);
        $this->assertStringContainsString('# Nazwa firmy', $issues['llms_txt_invalid']['description']);
        $this->assertSame(['https://firma.example/stara-strona'], $issues['llms_txt_broken_links']['urls']);
    }

    public function test_robots_blocking_ai_search_and_training_bots(): void
    {
        $robots = "User-agent: GPTBot\nUser-agent: CCBot\nDisallow: /\n\nUser-agent: PerplexityBot\nDisallow: /\n\nUser-agent: OAI-SearchBot\nAllow: /\n\nUser-agent: *\nDisallow: /wp-admin/";
        $this->fake($robots, null);
        $issues = $this->issues();

        $this->assertSame('high', $issues['ai_search_blocked']['severity']);
        $this->assertSame(['PerplexityBot'], array_keys($issues['ai_search_blocked']['details']));
        $this->assertSame(['GPTBot', 'CCBot'], array_keys($issues['ai_training_blocked']['details']));
    }

    public function test_full_block_reported_once_as_critical(): void
    {
        $this->fake("User-agent: *\nDisallow: /", null);
        $issues = $this->issues();

        $this->assertTrue($issues->has('robots_block_all'));
        $this->assertFalse($issues->has('ai_search_blocked'), 'pełna blokada jest już krytycznym problemem – bez dublowania');
    }

    public function test_specific_group_overrides_wildcard_block(): void
    {
        $blocked = app(SiteAuditor::class)->blockedAiBots("User-agent: *\nDisallow: /\n\nUser-agent: ClaudeBot\nUser-agent: Claude-SearchBot\nAllow: /");
        $bots = array_column($blocked, 'bot');

        $this->assertNotContains('ClaudeBot', $bots);
        $this->assertNotContains('Claude-SearchBot', $bots);
        $this->assertContains('GPTBot', $bots);
    }
}
