<?php
namespace MOC\NotFound\ViewHelpers;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Http\Client\CurlEngine;
use Neos\Flow\Http\Client\CurlEngineException;
use Neos\Flow\Http\Helper\RequestInformationHelper;
use Neos\Flow\Mvc\Exception\NoMatchingRouteException;
use Neos\FluidAdaptor\Core\ViewHelper\AbstractViewHelper;
use Neos\FluidAdaptor\Core\ViewHelper\Exception as ViewHelperException;
use Neos\Http\Factories\ServerRequestFactory;
use Neos\Neos\Domain\Service\ContentDimensionPresetSourceInterface;
use Neos\Neos\Routing\FrontendNodeRoutePartHandler;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Loads the content of a given URL
 */
class RequestViewHelper extends AbstractViewHelper
{
    /**
     * @Flow\Inject(lazy=false)
     * @var Bootstrap
     */
    protected $bootstrap;

    /**
     * @Flow\Inject
     * @var ContentDimensionPresetSourceInterface
     */
    protected $contentDimensionPresetSource;

    /**
     * @Flow\Inject
     * @var ServerRequestFactory
     */
    protected $serverRequestFactory;

    /**
     * @Flow\InjectConfiguration(path="routing.supportEmptySegmentForDimensions", package="Neos.Neos")
     * @var boolean
     */
    protected $supportEmptySegmentForDimensions;

    /**
     * @return void
     * @throws ViewHelperException
     * @api
     */
    public function initializeArguments()
    {
        parent::initializeArguments();
        $this->registerArgument('path', 'string', 'Path', false, '404');
    }

    /**
     * @return string
     * @throws \RuntimeException
     * @throws CurlEngineException
     * @throws NoMatchingRouteException
     */
    public function render() : string
    {
        $path = $this->arguments['path'];
        $this->appendFirstUriPartIfValidDimension($path);

        $request = $this->bootstrap->getActiveRequestHandler()->getHttpRequest();
        \assert($request instanceof ServerRequestInterface);

        $userAgent = $request->getHeader('User-Agent');
        if (isset($userAgent[0]) && strncmp($userAgent[0], 'Flow', 4) === 0) {
            // To prevent a request loop, requests from Flow will be ignored.
            return '';
        }

        $uri = RequestInformationHelper::generateBaseUri($request)->withPath($path);
        // By default, the ServerRequestFactory sets the User-Agent header to "Flow/" followed by the version branch.
        $serverRequest = $this->serverRequestFactory->createServerRequest('GET', $uri);
        $response = (new CurlEngine())->sendRequest($serverRequest);

        if ($response->getStatusCode() === 404) {
            throw new NoMatchingRouteException(sprintf('Uri with path "%s" could not be found.', $uri), 1426446160);
        }

        return $response->getBody()->getContents();
    }

    /**
     * @param string $path
     * @return void
     */
    protected function appendFirstUriPartIfValidDimension(&$path)
    {
        $requestPath = ltrim($this->controllerContext->getRequest()->getHttpRequest()->getUri()->getPath(), '/');
        $matches = [];
        preg_match(FrontendNodeRoutePartHandler::DIMENSION_REQUEST_PATH_MATCHER, $requestPath, $matches);
        if (!isset($matches['firstUriPart']) && !isset($matches['dimensionPresetUriSegments'])) {
            return;
        }

        $dimensionPresets = $this->contentDimensionPresetSource->getAllPresets();
        if (count($dimensionPresets) === 0) {
            return;
        }

        $firstUriPartExploded = explode('_', $matches['firstUriPart'] ?: $matches['dimensionPresetUriSegments']);
        if ($this->supportEmptySegmentForDimensions) {
            foreach ($firstUriPartExploded as $uriSegment) {
                $uriSegmentIsValid = false;
                foreach ($dimensionPresets as $dimensionName => $dimensionPreset) {
                    $preset = $this->contentDimensionPresetSource->findPresetByUriSegment($dimensionName, $uriSegment);
                    if ($preset !== null) {
                        $uriSegmentIsValid = true;
                        break;
                    }
                }
                if (!$uriSegmentIsValid) {
                    return;
                }
            }
        } else {
            if (count($firstUriPartExploded) !== count($dimensionPresets)) {
                $this->appendDefaultDimensionPresetUriSegments($dimensionPresets, $path);
                return;
            }
            foreach ($dimensionPresets as $dimensionName => $dimensionPreset) {
                $uriSegment = array_shift($firstUriPartExploded);
                $preset = $this->contentDimensionPresetSource->findPresetByUriSegment($dimensionName, $uriSegment);
                if ($preset === null) {
                    $this->appendDefaultDimensionPresetUriSegments($dimensionPresets, $path);
                    return;
                }
            }
        }

        $path = $matches['firstUriPart'] . '/' . $path;
    }

    /**
     * @param array $dimensionPresets
     * @param string $path
     * @return void
     */
    protected function appendDefaultDimensionPresetUriSegments(array $dimensionPresets, &$path) {
        $defaultDimensionPresetUriSegments = [];
        foreach ($dimensionPresets as $dimensionName => $dimensionPreset) {
            $defaultDimensionPresetUriSegments[] = $dimensionPreset['presets'][$dimensionPreset['defaultPreset']]['uriSegment'];
        }
        $path = implode('_', $defaultDimensionPresetUriSegments) . '/' . $path;
    }
}
