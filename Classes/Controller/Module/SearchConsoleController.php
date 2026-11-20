<?php
declare(strict_types=1);

namespace Netlogix\GoogleSearchConsoleInspector\Controller\Module;

use Neos\Flow\Annotations as Flow;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Netlogix\GoogleSearchConsoleInspector\Domain\Service\SiteReportService;

class SearchConsoleController extends AbstractModuleController
{
    /**
     * @var SiteReportService
     * @Flow\Inject
     */
    protected $siteReportService;

    public function indexAction(?string $property = null): void
    {
        $properties = $this->siteReportService->properties();
        if (!in_array($property, $properties, true)) {
            $property = $properties[0] ?? null;
        }

        $this->view->assign('properties', $properties);
        $this->view->assign('property', $property);
        if ($property === null) {
            return;
        }

        try {
            $this->view->assign('overview', $this->siteReportService->overview($property));
        } catch (\Google\Service\Exception $e) {
            // the plain message is the raw JSON response
            $this->view->assign('error', $e->getErrors()[0]['message'] ?? $e->getMessage());
        } catch (\Throwable $t) {
            $this->view->assign('error', $t->getMessage());
        }
        $this->view->assign('report', $this->siteReportService->report($property));
    }
}
