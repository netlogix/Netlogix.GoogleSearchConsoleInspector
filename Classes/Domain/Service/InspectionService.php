<?php
declare(strict_types=1);

namespace Netlogix\GoogleSearchConsoleInspector\Domain\Service;

use Google\Client;
use Google\Service\SearchConsole;
use GuzzleHttp\Psr7\Uri;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Annotations as Flow;
use Netlogix\GoogleSearchConsoleInspector\Exception\NoSiteUrlFoundForUri;

/**
 * @Flow\Scope("singleton")
 */
final class InspectionService extends SearchConsole
{
    /**
     * @var array
     * @Flow\InjectConfiguration(path="siteUrlMapping")
     */
    protected array $siteUrlMapping = [];

    /**
     * @var VariableFrontend
     */
    protected $cache;

    public function __construct(Client $client)
    {
        parent::__construct($client);
    }

    public function inspectUri(Uri $uri, bool $force = false): SearchConsole\UrlInspectionResult
    {
        $cacheIdentifier = self::cacheIdentifier($uri);
        if ($this->cache->has($cacheIdentifier) && !$force) {
            return $this->cache->get($cacheIdentifier);
        }

        $request = new SearchConsole\InspectUrlIndexRequest();

        [$siteUrl, $inspectionUri] = $this->resolveSiteUrlAndInspectionUri($uri);

        $request->setInspectionUrl((string)$inspectionUri);
        $request->setSiteUrl($siteUrl);
        // TODO: use language of current user
        $request->setLanguageCode('en-US');

        $inspector = $this->urlInspection_index;
        assert($inspector instanceof SearchConsole\Resource\UrlInspectionIndex);
        $response = $inspector->inspect($request);
        $result = $response->getInspectionResult();
        $this->cache->set($cacheIdentifier, $result, [sha1($siteUrl)]);

        return $result;
    }

    /**
     * Resolves the Search Console property for the given URI and rewrites the URI onto the
     * property's canonical (first-listed) URL prefix. Additional prefixes (e.g. a local dev
     * domain) are only used to recognize which property a URL belongs to - the Search Console
     * API rejects an inspectionUrl that isn't actually part of the given siteUrl property, so
     * a dev-domain URL must never be sent to Google as-is.
     *
     * @return array{0: string, 1: Uri}
     */
    private function resolveSiteUrlAndInspectionUri(Uri $uri): array
    {
        foreach ($this->siteUrlMapping as $property => $urlPrefixes) {
            foreach ($urlPrefixes as $urlPrefix) {
                if (strpos((string)$uri, $urlPrefix) === 0) {
                    $canonicalPrefix = reset($urlPrefixes);
                    $inspectionUri = $canonicalPrefix . substr((string)$uri, strlen($urlPrefix));
                    return [$property, new Uri($inspectionUri)];
                }
            }
        }

        throw new NoSiteUrlFoundForUri(
            sprintf('No siteUrl for the search console property could be found for the URL "%s"', $uri),
            1662829443
        );
    }

    private static function cacheIdentifier(Uri $uri): string
    {
        return sha1((string)$uri);
    }

}
