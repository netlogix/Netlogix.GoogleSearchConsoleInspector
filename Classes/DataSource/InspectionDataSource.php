<?php
declare(strict_types=1);

namespace Netlogix\GoogleSearchConsoleInspector\DataSource;

use GuzzleHttp\Psr7\Uri;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAddress;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Neos\Neos\FrontendRouting\NodeUriBuilderFactory;
use Neos\Neos\FrontendRouting\Options;
use Neos\Neos\Fusion\Helper\NodeHelper;
use Neos\Neos\Service\DataSource\AbstractDataSource;
use Neos\Neos\Service\LinkingService;
use Netlogix\GoogleSearchConsoleInspector\Domain\Service\InspectionService;
use Psr\Http\Message\UriInterface;

final class InspectionDataSource extends AbstractDataSource
{

    protected static $identifier = 'nlxGoogleSearchConsoleInspectorInspection';

    /**
     * @var InspectionService
     * @Flow\Inject
     */
    protected $inspectionService;

    /**
     * @var ObjectManagerInterface
     * @Flow\Inject
     */
    protected $objectManager;

    public function getData(NodeInterface|Node $node = null, array $arguments = [])
    {
        if ($node === null) {
            return null;
        }

        try {
            if ($node instanceof Node) {
                $uri = $this->neos9Uri($node);
            } else {
                $uri = $this->neos8Uri($node);
            }
            if ($uri === null) {
                return [
                    'error' => [
                        'message' => 'Node was not found in Live workspace.'
                    ]
                ];
            }

            $result = $this->inspectionService->inspectUri($uri);

            return [
                'data' => json_decode(json_encode($result->toSimpleObject()), true),
            ];
        } catch (\Throwable $t) {
            return [
                'error' => [
                    'message' => $t->getMessage(),
                ],
            ];
        }
    }

    private function neos8Uri(NodeInterface $node): ?UriInterface
    {
        if (!$node->getNodeType()->isOfType('Neos.Neos:Document')) {
            return null;
        }

        $contentContextFactory = $this->objectManager->get(ContentContextFactory::class);
        $liveContext = $contentContextFactory->create(
            array_merge(
                $node->getContext()->getProperties(),
                ['workspaceName' => 'live']
            )
        );
        $node = $liveContext->getNodeByIdentifier($node->getIdentifier());
        if (!$node) {
            return null;
        }

        $linkingService = $this->objectManager->get(LinkingService::class);
        $uri = $linkingService
            ->createNodeUri(
                $this->controllerContext,
                $node,
                null,
                'html',
                true
            );

        return new Uri($uri);
    }

    private function neos9Uri(Node $node): ?UriInterface
    {
        $nodeHelper = $this->objectManager->get(NodeHelper::class);
        if (!$nodeHelper->isOfType($node, 'Neos.Neos:Document')) {
            return null;
        }

        $nodeUriBuilderFactory = $this->objectManager->get(NodeUriBuilderFactory::class);
        $nodeUriBuilder = $nodeUriBuilderFactory->forActionRequest(
            $this->controllerContext->getRequest()
        );
        $nodeAddress = NodeAddress::fromNode($node);

        return $nodeUriBuilder
            ->uriFor(
                NodeAddress::create(
                    $nodeAddress->contentRepositoryId,
                    WorkspaceName::forLive(),
                    $nodeAddress->dimensionSpacePoint,
                    $nodeAddress->aggregateId
                ),
                Options::createForceAbsolute()
            );
    }
}
