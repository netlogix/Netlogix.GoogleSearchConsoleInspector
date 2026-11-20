<?php
declare(strict_types=1);

namespace Netlogix\GoogleSearchConsoleInspector\Tests\Unit\Domain\Service;

use Google\Client;
use Google\Service\Exception as GoogleServiceException;
use Google\Service\SearchConsole\IndexStatusInspectionResult;
use Google\Service\SearchConsole\InspectUrlIndexRequest;
use Google\Service\SearchConsole\InspectUrlIndexResponse;
use Google\Service\SearchConsole\Resource\Sitemaps;
use Google\Service\SearchConsole\Resource\Sites;
use Google\Service\SearchConsole\Resource\UrlInspectionIndex;
use Google\Service\SearchConsole\SitemapsListResponse;
use Google\Service\SearchConsole\SitesListResponse;
use Google\Service\SearchConsole\UrlInspectionResult;
use Google\Service\SearchConsole\WmxSite;
use Google\Service\SearchConsole\WmxSitemap;
use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Neos\Cache\Backend\TransientMemoryBackend;
use Neos\Cache\EnvironmentConfiguration;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Tests\UnitTestCase;
use Netlogix\GoogleSearchConsoleInspector\Domain\Service\InspectionService;
use Netlogix\GoogleSearchConsoleInspector\Domain\Service\SiteReportService;

class SiteReportServiceTest extends UnitTestCase
{
    private const PROPERTY = 'sc-domain:example.org';

    private const SITEMAP_INDEX = '<?xml version="1.0"?>
        <sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
            <sitemap><loc>https://www.example.org/pages.xml</loc></sitemap>
        </sitemapindex>';

    private const SITEMAP = '<?xml version="1.0"?>
        <urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
            <url><loc>https://www.example.org/</loc></url>
            <url><loc> https://www.example.org/a </loc></url>
            <url><loc>https://www.example.org/b</loc></url>
        </urlset>';

    private SiteReportService $service;

    private InspectionService $inspectionService;

    private MockHandler $http;

    protected function setUp(): void
    {
        parent::setUp();

        $mapping = [
            self::PROPERTY => ['https://www.example.org'],
            'sc-domain:example.com' => ['https://www.example.com'],
        ];

        $sitemap = new WmxSitemap();
        $sitemap->setPath('https://www.example.org/sitemap.xml');
        $sitemaps = $this->createMock(Sitemaps::class);
        $sitemaps->method('listSitemaps')->willReturnCallback(static function () use ($sitemap) {
            $response = new SitemapsListResponse();
            $response->setSitemap([$sitemap]);
            return $response;
        });

        $this->inspectionService = new InspectionService(new Client());
        $this->inspectionService->sitemaps = $sitemaps;
        $this->inject($this->inspectionService, 'siteUrlMapping', $mapping);
        $this->inject($this->inspectionService, 'cache', self::cache());

        $this->http = new MockHandler();
        $this->service = new SiteReportService();
        $this->inject($this->service, 'siteUrlMapping', $mapping);
        $this->inject($this->service, 'inspectionService', $this->inspectionService);
        $this->inject($this->service, 'responseCache', self::cache());
        $this->inject($this->service, 'reportCache', self::cache());
        $this->inject($this->service, 'httpClient', new HttpClient(['handler' => $this->http]));
    }

    /**
     * @test
     */
    public function permissionLevelsAreNullForPropertiesTheServiceAccountCannotAccess(): void
    {
        $site = new WmxSite();
        $site->setSiteUrl(self::PROPERTY);
        $site->setPermissionLevel('siteFullUser');
        $response = new SitesListResponse();
        $response->setSiteEntry([$site]);
        $sites = $this->createMock(Sites::class);
        $sites->method('listSites')->willReturn($response);
        $this->inspectionService->sites = $sites;

        self::assertSame(
            [self::PROPERTY => 'siteFullUser', 'sc-domain:example.com' => null],
            $this->service->permissionLevels()
        );
    }

    /**
     * @test
     */
    public function sitemapUrlsFollowsSitemapIndexFiles(): void
    {
        $this->http->append(new Response(200, [], self::SITEMAP_INDEX), new Response(200, [], self::SITEMAP));

        self::assertSame(
            ['https://www.example.org/', 'https://www.example.org/a', 'https://www.example.org/b'],
            $this->service->sitemapUrls(self::PROPERTY, 10)
        );
    }

    /**
     * @test
     */
    public function sitemapUrlsRespectsTheLimit(): void
    {
        $this->http->append(new Response(200, [], self::SITEMAP));

        self::assertCount(2, $this->service->sitemapUrls(self::PROPERTY, 2));
    }

    /**
     * @test
     */
    public function inspectSitemapsStoresOnlyUrlsThatAreNotIndexed(): void
    {
        $this->http->append(new Response(200, [], self::SITEMAP));
        $this->mockInspection(static fn (string $url) => str_ends_with($url, '/a') ? 'NEUTRAL' : 'PASS');

        $report = $this->service->inspectSitemaps(self::PROPERTY, 10);

        self::assertSame(3, $report['inspected']);
        self::assertSame(0, $report['errors']);
        self::assertSame(['https://www.example.org/a'], array_column($report['notIndexed'], 'url'));
        self::assertSame('NEUTRAL', $report['notIndexed'][0]['verdict']);
        self::assertSame($report, $this->service->report(self::PROPERTY));
    }

    /**
     * @test
     */
    public function inspectSitemapsStopsWhenTheQuotaIsExceeded(): void
    {
        $this->http->append(new Response(200, [], self::SITEMAP));
        $calls = 0;
        $this->mockInspection(static function () use (&$calls) {
            $calls++;
            throw new GoogleServiceException('Quota exceeded', 429);
        });

        $report = $this->service->inspectSitemaps(self::PROPERTY, 10);

        self::assertSame(1, $calls);
        self::assertSame(['inspected' => 0, 'errors' => 1], array_intersect_key($report, ['inspected' => 0, 'errors' => 0]));
    }

    /**
     * @test
     */
    public function reportIsNullBeforeTheFirstRun(): void
    {
        self::assertNull($this->service->report(self::PROPERTY));
    }

    private function mockInspection(\Closure $verdictForUrl): void
    {
        $inspector = $this->createMock(UrlInspectionIndex::class);
        $inspector->method('inspect')->willReturnCallback(
            static function (InspectUrlIndexRequest $request) use ($verdictForUrl) {
                $status = new IndexStatusInspectionResult();
                $status->setVerdict($verdictForUrl($request->getInspectionUrl()));
                $result = new UrlInspectionResult();
                $result->setIndexStatusResult($status);
                $response = new InspectUrlIndexResponse();
                $response->setInspectionResult($result);
                return $response;
            }
        );
        $this->inspectionService->urlInspection_index = $inspector;
    }

    private static function cache(): VariableFrontend
    {
        $backend = new TransientMemoryBackend(new EnvironmentConfiguration('test', sys_get_temp_dir()));
        $cache = new VariableFrontend('test', $backend);
        $backend->setCache($cache);

        return $cache;
    }
}
