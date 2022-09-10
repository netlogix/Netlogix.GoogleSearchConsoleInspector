<?php
declare(strict_types=1);

namespace Netlogix\GoogleSearchConsoleInspector\DataSource;

use GuzzleHttp\Psr7\Uri;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Neos\Neos\Service\DataSource\AbstractDataSource;
use Neos\Neos\Service\LinkingService;
use Netlogix\GoogleSearchConsoleInspector\Domain\Service\InspectionService;

final class InspectionDataSource extends AbstractDataSource
{

    protected static $identifier = 'nlxGoogleSearchConsoleInspectorInspection';

    /**
     * @var LinkingService
     * @Flow\Inject
     */
    protected $linkingService;

    /**
     * @var InspectionService
     * @Flow\Inject
     */
    protected $inspectionService;

    /**
     * @var ContentContextFactory
     * @Flow\Inject
     */
    protected $contentContextFactory;

    public function getData(NodeInterface $node = null, array $arguments = [])
    {
        if ($node === null || !$node->getNodeType()->isOfType('Neos.Neos:Document')) {
            return null;
        }

        $liveContext = $this->contentContextFactory->create(
            array_merge(
                $node->getContext()->getProperties(),
                ['workspaceName' => 'live']
            )
        );
        $node = $liveContext->getNodeByIdentifier($node->getIdentifier());
        if (!$node) {
            return [
                'error' => [
                    'message' => 'Node was not found in Live workspace.'
                ]
            ];
        }

        try {
            $uri = $this->linkingService
                ->createNodeUri(
                    $this->controllerContext,
                    $node,
                    null,
                    'html',
                    true
                );
            $result = $this->inspectionService->inspectUri(new Uri($uri));

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
}
