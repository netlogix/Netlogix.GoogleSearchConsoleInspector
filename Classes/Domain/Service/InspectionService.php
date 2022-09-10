<?php
declare(strict_types=1);

namespace Netlogix\GoogleSearchConsoleInspector\Domain\Service;

use Google\Client;
use Google\Service\SearchConsole;
use GuzzleHttp\Psr7\Uri;
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

        $client->addScope(SearchConsole::WEBMASTERS_READONLY);
        $client->setAuthConfig(getenv('GOOGLE_APPLICATION_CREDENTIALS'));
    }

    public function inspectUri(Uri $uri, bool $force = false): SearchConsole\UrlInspectionResult
    {
        $cacheIdentifier = self::cacheIdentifier($uri);
        if ($this->cache->has($cacheIdentifier) && !$force) {
            return $this->cache->get($cacheIdentifier);
        }

        $request = new SearchConsole\InspectUrlIndexRequest();

        $siteUrl = $this->getSiteUrlForUri($uri);

        $request->setInspectionUrl((string)$uri);
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

    private function getSiteUrlForUri(Uri $uri): string
    {
        foreach ($this->siteUrlMapping as $property => $urlPrefixes) {
            foreach ($urlPrefixes as $urlPrefix) {
                if (strpos((string)$uri, $urlPrefix) === 0) {
                    return $property;
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
