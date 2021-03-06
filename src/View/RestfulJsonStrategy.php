<?php
/**
 * @link      https://github.com/weierophinney/PhlyRestfully for the canonical source repository
 * @copyright Copyright (c) 2013 Matthew Weier O'Phinney
 * @license   http://opensource.org/licenses/BSD-2-Clause BSD-2-Clause
 * @package   PhlyRestfully
 */

namespace PhlyRestfully\View;

use PhlyRestfully\ApiProblem;
use Laminas\View\Strategy\JsonStrategy;
use Laminas\View\ViewEvent;

/**
 * Extension of the JSON strategy to handle the RestfulJsonModel and provide
 * a Content-Type header appropriate to the response it describes.
 *
 * This will give the following content types:
 *
 * - application/hal+json for a result that contains HAL-compliant links
 * - application/api-problem+json for a result that contains a Problem
 *   API-formatted response
 * - application/json for all other responses
 */
class RestfulJsonStrategy extends JsonStrategy
{
    /**
     * @var string
     */
    protected $contentType = 'application/json';

    /**
     * @var RestfulJsonRenderer
     */
    protected $renderer;

    public function __construct(RestfulJsonRenderer $renderer)
    {
        parent::__construct($renderer);
    }

    /**
     * Detect if we should use the RestfulJsonRenderer based on model type.
     *
     * @param  ViewEvent $e
     * @return null|RestfulJsonRenderer
     */
    public function selectRenderer(ViewEvent $e)
    {
        $model = $e->getModel();

        if (!$model instanceof RestfulJsonModel) {
            // unrecognized model; do nothing
            return null;
        }

        // JsonModel found
        return $this->renderer;
    }

    /**
     * Inject the response
     *
     * Injects the response with the rendered content, and sets the content
     * type based on the detection that occurred during renderer selection.
     *
     * @param  ViewEvent $e
     * @return void
     */
    public function injectResponse(ViewEvent $e): void
    {
        $renderer = $e->getRenderer();
        if ($renderer !== $this->renderer) {
            // Discovered renderer is not ours; do nothing
            return;
        }

        $result   = $e->getResult();
        if (!is_string($result)) {
            // We don't have a string, and thus, no JSON
            return;
        }

        $model       = $e->getModel();
        $contentType = $this->contentType;
        /** @var \Laminas\Http\Response $response */
        $response    = $e->getResponse();

        if ($this->renderer->isApiProblem()) {
            $contentType = 'application/api-problem+json';
            $statusCode  = $this->getStatusCodeFromApiProblem($this->renderer->getApiProblem());
            $response->setStatusCode((int) $statusCode);
        } elseif ($model instanceof RestfulJsonModel && $model->isApiProblem()) {
            $contentType = 'application/api-problem+json';
            $statusCode  = $this->getStatusCodeFromApiProblem($model->getPayload());
            $response->setStatusCode((int) $statusCode);
        } elseif ($model instanceof RestfulJsonModel
            && ($model->isHalCollection() || $model->isHalResource())
        ) {
            $contentType = 'application/hal+json';
        }

        // Populate response
        $response->setContent($result);
        $headers = $response->getHeaders();
        $headers->addHeaderLine('content-type', $contentType);
    }

    /**
     * Retrieve the HTTP status from an ApiProblem object
     *
     * Ensures that the status falls within the acceptable range (100 - 599).
     *
     * @param  ApiProblem $problem
     * @return int|string
     */
    protected function getStatusCodeFromApiProblem(ApiProblem $problem)
    {
        $status = $problem->httpStatus;
        if ($status < 100 || $status >= 600) {
            return 500;
        }
        return $status;
    }
}
