<?php
namespace Sitegeist\Monocle\Controller;

/**
 * This file is part of the Sitegeist.Monocle package
 *
 * (c) 2016
 * Martin Ficzel <ficzel@sitegeist.de>
 * Wilhelm Behncke <behncke@sitegeist.de>
 *
 * This package is Open Source Software. For the full copyright and license
 * information, please view the LICENSE file which was distributed with this
 * source code.
 */

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\ResourceManagement\ResourceManager;
use Sitegeist\Monocle\Fusion\FusionService;
use Sitegeist\Monocle\Fusion\FusionView;
use Sitegeist\Monocle\Fusion\ReverseFusionParser;
use Sitegeist\Monocle\Service\PackageKeyTrait;
use Symfony\Component\Yaml\Yaml;
use Sitegeist\Monocle\Service\ConfigurationService;

/**
 * Class ApiController
 * @package Sitegeist\Monocle\Controller
 */
class ApiController extends ActionController
{
    use PackageKeyTrait;

    /**
     * @var array
     */
    protected $defaultViewObjectName = 'Neos\Flow\Mvc\View\JsonView';

    /**
     * @Flow\Inject
     * @var FusionService
     */
    protected $fusionService;

    /**
     * @Flow\Inject
     * @var ResourceManager
     */
    protected $resourceManager;

    /**
     * @var array
     * @Flow\InjectConfiguration("preview.additionalResources")
     */
    protected $additionalResources;

    /**
     * @Flow\Inject
     * @var ConfigurationService
     */
    protected $configurationService;

    /**
     * Get all styleguide objects
     *
     * @Flow\SkipCsrfProtection
     * @param string $sitePackageKey
     * @return void
     */
    public function styleguideObjectsAction($sitePackageKey = null)
    {
        $sitePackageKey = $sitePackageKey ?: $this->getDefaultSitePackageKey();

        $fusionAst = $this->fusionService->getMergedFusionObjectTreeForSitePackage($sitePackageKey);
        $styleguideObjects = $this->fusionService->getStyleguideObjectsFromFusionAst($fusionAst);
        $prototypeStructures = $this->configurationService->getSiteConfiguration($sitePackageKey, 'ui.structure');

        foreach ($styleguideObjects as $prototypeName => &$styleguideObject) {
            $styleguideObject['structure'] = $this->getStructureForPrototypeName($prototypeStructures, $prototypeName);
        }

        $hiddenPrototypeNamePatterns = $this->configurationService->getSiteConfiguration($sitePackageKey, 'hiddenPrototypeNamePatterns');
        if (is_array($hiddenPrototypeNamePatterns)) {
            foreach ($hiddenPrototypeNamePatterns as $pattern) {
                $styleguideObjects = array_filter(
                    $styleguideObjects,
                    function ($prototypeName) use ($pattern) {
                        return fnmatch($pattern, $prototypeName) === false;
                    },
                    ARRAY_FILTER_USE_KEY
                );
            }
        }

        $this->view->assign('value', $styleguideObjects);
    }

    /**
     * Find the matching structure for a prototype
     *
     * @param $prototypeStructures
     * @param $prototypeName
     * @return array
     */
    protected function getStructureForPrototypeName($prototypeStructures, $prototypeName)
    {
        foreach ($prototypeStructures as $structure) {
            if (preg_match(sprintf('!%s!', $structure['match']), $prototypeName)) {
                return $structure;
            }
        }

        return [
            'label' => 'Other',
            'icon' => 'icon-question',
            'color' => 'white'
        ];
    }

    /**
     * Get all site packages
     *
     * @Flow\SkipCsrfProtection
     * @return void
     */
    public function sitePackagesAction()
    {
        $sitePackageKeys = $this->getActiveSitePackageKeys();
        $result = [];

        foreach ($sitePackageKeys as $sitePackageKey) {
            $result[$sitePackageKey] = $sitePackageKey;
        }

        $this->view->assign('value', $result);
    }

    /**
    /**
     * Get all configured viewport presets
     *
     * @Flow\SkipCsrfProtection
     * @param string $sitePackageKey
     * @return void
     */
    public function viewportPresetsAction($sitePackageKey = null)
    {
        $sitePackageKey = $sitePackageKey ?: $this->getDefaultSitePackageKey();
        $this->view->assign('value', $this->configurationService->getSiteConfiguration($sitePackageKey, 'ui.viewportPresets'));
    }

    /**
    /**
     * Get all configured locale presets
     *
     * @Flow\SkipCsrfProtection
     * @param string $sitePackageKey
     * @return void
     */
    public function localePresetsAction($sitePackageKey = null)
    {
        $sitePackageKey = $sitePackageKey ?: $this->getDefaultSitePackageKey();
        $this->view->assign('value', $this->configurationService->getSiteConfiguration($sitePackageKey, 'ui.localePresets'));
    }

    /**
     * Render the given prototype
     *
     * @Flow\SkipCsrfProtection
     * @param string $prototypeName
     * @param string $sitePackageKey
     * @return void
     */
    public function renderPrototypeAction($prototypeName, $sitePackageKey = null)
    {
        $sitePackageKey = $sitePackageKey ?: $this->getDefaultSitePackageKey();

        $prototypePreviewRenderPath = FusionService::RENDERPATH_DISCRIMINATOR . str_replace(['.', ':'], ['_', '__'], $prototypeName);

        // render html
        $fusionView = new FusionView();
        $fusionView->setControllerContext($this->getControllerContext());
        $fusionView->setFusionPath($prototypePreviewRenderPath);
        $fusionView->setPackageKey($sitePackageKey);

        // render fusion source
        $fusionObjectTree = $this->fusionService->getMergedFusionObjectTreeForSitePackage($sitePackageKey);
        $fusionAst =  $fusionObjectTree['__prototypes'][$prototypeName];
        $fusionCode = ReverseFusionParser::restorePrototypeCode($prototypeName, $fusionAst);

        $result = [
            'prototypeName' => $prototypeName,
            'renderedCode' => $fusionCode,
            'parsedCode' => Yaml::dump($fusionAst, 99),
            'fusionAst' => $fusionAst,
            'anatomy' => $this->fusionService->getAnatomicalPrototypeTreeFromAstExcerpt($fusionAst)
        ];

        $this->view->assign('value', $result);
    }
}
