<?php
/**
 * @link      https://github.com/weierophinney/PhlyRestfully for the canonical source repository
 * @copyright Copyright (c) 2013 Matthew Weier O'Phinney
 * @license   http://opensource.org/licenses/BSD-2-Clause BSD-2-Clause
 * @package   PhlyRestfully
 */

namespace PhlyRestfully;

use Laminas\Http\Response;
use Laminas\Mvc\Controller\AbstractRestfulController;
use Laminas\Mvc\MvcEvent;
use Laminas\Paginator\Paginator;

/**
 * Controller for handling resources.
 *
 * Extends the base AbstractRestfulController in order to provide very specific
 * semantics for building a RESTful JSON service. All operations return either
 *
 * - a HAL-compliant response with appropriate hypermedia links
 * - a Problem API-compliant response for reporting an error condition
 *
 * You may specify what specific HTTP method types you wish to respond to, and
 * OPTIONS will then report those; attempting any HTTP method falling outside
 * that list will result in a 405 (Method Not Allowed) response.
 *
 * I recommend using resource-specific factories when using this controller,
 * to allow injecting the specific resource you wish to use (and its listeners),
 * which will also allow you to have multiple instances of the controller when
 * desired.
 *
 * @see http://tools.ietf.org/html/draft-kelly-json-hal-03
 * @see http://tools.ietf.org/html/draft-nottingham-http-problem-02
 */
class ResourceController extends AbstractRestfulController
{
    /**
     * Criteria for the AcceptableViewModelSelector
     *
     * @var array
     */
    protected $acceptCriteria = [
        View\RestfulJsonModel::class => [
            '*/json',
        ],
    ];

    /**
     * HTTP methods we allow for the resource (collection); used by options()
     *
     * HEAD and OPTIONS are always available.
     *
     * @var array
     */
    protected $collectionHttpOptions = [
        'GET',
        'POST',
    ];

    /**
     * Name of the collections entry in a HalCollection
     *
     * @var string
     */
    protected $collectionName = 'items';

    /**
     * Content types that will trigger marshalling data from the request body.
     *
     * @var array
     */
    protected $contentTypes = [
        self::CONTENT_TYPE_JSON => [
            'application/json',
            'application/hal+json',
        ],
    ];

    /**
     * Number of resources to return per page.  If $pageSizeParameter is
     * specified, then it will override this when provided in a request.
     *
     * @var int
     */
    protected $pageSize = 30;

    /**
     * A query parameter to use to specify the number of records to return in
     * each collection page.  If not provided, $pageSize will be used as a
     * default value.
     *
     * Leave null to disable this functionality and always use $pageSize.
     *
     * @var string
     */
    protected $pageSizeParam;

    /**
     * @var ResourceInterface
     */
    protected $resource;

    /**
     * HTTP methods we allow for individual resources; used by options()
     *
     * HEAD and OPTIONS are always available.
     *
     * @var array
     */
    protected $resourceHttpOptions = [
        'DELETE',
        'GET',
        'PATCH',
        'PUT',
    ];

    /**
     * Route name that resolves to this resource; used to generate links.
     *
     * @var string
     */
    protected $route = '';

    /**
     * Constructor
     *
     * Allows you to set the event identifier, which can be useful to allow multiple
     * instances of this controller to react to different sets of shared events.
     *
     * @param  null|string $eventIdentifier
     */
    public function __construct($eventIdentifier = null)
    {
        if (null !== $eventIdentifier) {
            $this->eventIdentifier = $eventIdentifier;
        }
    }

    /**
     * Set the Accept header criteria for use with the AcceptableViewModelSelector
     *
     * @param  array $criteria
     * @return void
     */
    public function setAcceptCriteria(array $criteria): void
    {
        $this->acceptCriteria = $criteria;
    }

    /**
     * Set the allowed HTTP OPTIONS for the resource (collection)
     *
     * @param  array $options
     * @return void
     */
    public function setCollectionHttpOptions(array $options): void
    {
        $this->collectionHttpOptions = $options;
    }

    /**
     * Set the name to which to assign a collection in a HalCollection
     *
     * @return void
     */
    public function setCollectionName(string $name): void
    {
        $this->collectionName = $name;
    }

    /**
     * Set the allowed content types for the resource (collection)
     *
     * @param  array $contentTypes
     * @return void
     */
    public function setContentTypes(array $contentTypes): void
    {
        $this->contentTypes = $contentTypes;
    }

    public function getContentTypes(): array
    {
        return $this->contentTypes;
    }

    /**
     * Set the default page size for paginated responses
     *
     * @return void
     */
    public function setPageSize(int $count): void
    {
        $this->pageSize = $count;
    }

    /**
     * Set the page size parameter for paginated responses.
     *
     * @return void
     */
    public function setPageSizeParam(string $param): void
    {
        $this->pageSizeParam = $param;
    }

    /**
     * Inject the resource with which this controller will communicate.
     *
     * @param  ResourceInterface $resource
     * @return void
     */
    public function setResource(ResourceInterface $resource): void
    {
        $this->resource = $resource;
    }

    /**
     * Returns the resource
     *
     * @throws Exception\DomainException If no resource has been set
     *
     * @return ResourceInterface
     */
    public function getResource()
    {
        if ($this->resource === null) {
            throw new Exception\DomainException('No resource has been set.');
        }

        return $this->resource;
    }

    /**
     * Set the allowed HTTP OPTIONS for a resource
     *
     * @param  array $options
     * @return void
     */
    public function setResourceHttpOptions(array $options): void
    {
        $this->resourceHttpOptions = $options;
    }

    /**
     * Inject the route name for this resource.
     *
     * @param  string $route
     * @return void
     */
    public function setRoute($route): void
    {
        $this->route = $route;
    }

    /**
     * Handle the dispatch event
     *
     * Does several "pre-flight" checks:
     * - Raises an exception if no resource is composed.
     * - Raises an exception if no route is composed.
     * - Returns a 405 response if the current HTTP request method is not in
     *   $options
     *
     * When the dispatch is complete, it will check to see if an array was
     * returned; if so, it will cast it to a view model using the
     * AcceptableViewModelSelector plugin, and the $acceptCriteria property.
     *
     * @param  MvcEvent $e
     * @return mixed
     * @throws Exception\DomainException
     */
    public function onDispatch(MvcEvent $e)
    {
        if (!$this->resource) {
            throw new Exception\DomainException(sprintf(
                '%s requires that a %s\ResourceInterface object is composed; none provided',
                __CLASS__,
                __NAMESPACE__
            ));
        }

        if (!$this->route) {
            throw new Exception\DomainException(sprintf(
                '%s requires that a route name for the resource is composed; none provided',
                __CLASS__
            ));
        }

        // Check for an API-Problem in the event
        $return = $e->getParam('api-problem', false);

        // If no API-Problem, dispatch the parent event
        if (!$return) {
            $return = parent::onDispatch($e);
        }

        if (!$return instanceof ApiProblem
            && !$return instanceof HalResource
            && !$return instanceof HalCollection
        ) {
            return $return;
        }

        $viewModel = $this->acceptableViewModelSelector($this->acceptCriteria);
        $viewModel->setVariables(['payload' => $return]);

        if ($viewModel instanceof View\RestfulJsonModel) {
            $viewModel->setTerminal(true);
        }

        $e->setResult($viewModel);
        return $viewModel;
    }

    /**
     * Create a new resource
     *
     * @param  array $data
     * @return Response|ApiProblem|HalResource
     */
    public function create($data)
    {
        if (!$this->isMethodAllowedForCollection()) {
            return $this->createMethodNotAllowedResponse($this->collectionHttpOptions);
        }

        $events = $this->getEventManager();
        $events->trigger('create.pre', $this, ['data' => $data]);

        try {
            $resource = $this->resource->create($data);
        } catch (\Exception $e) {
            $code = (int) $e->getCode() ?: 500;
            return new ApiProblem($code, $e);
        }

        if ($resource instanceof ApiProblem) {
            return $resource;
        }

        $plugin   = $this->plugin('HalLinks');
        $resource = $plugin->createResource($resource, $this->route, $this->getIdentifierName());

        if ($resource instanceof ApiProblem) {
            return $resource;
        }

        $self = $resource->getLinks()->get('self');
        $self = $plugin->fromLink($self);

        /** @var \Laminas\Http\Response $response */
        $response = $this->getResponse();
        $response->setStatusCode(201);
        $response->getHeaders()->addHeaderLine('Location', $self);

        $events->trigger('create.post', $this, ['data' => $data, 'resource' => $resource]);

        return $resource;
    }

    /**
     * Respond to the PATCH method (partial update of existing resource) on
     * a collection, i.e. create and/or update multiple resources in a collection.
     *
     * @param array $data
     * @return array|Response|ApiProblem
     */
    public function patchList($data)
    {
        if (!$this->isMethodAllowedForCollection()) {
            return $this->createMethodNotAllowedResponse($this->collectionHttpOptions);
        }

        $events = $this->getEventManager();
        $events->trigger('patchList.pre', $this, ['data' => $data]);

        try {
            $collection = $this->resource->patchList($data);
        } catch (\Exception $e) {
            $code = (int) $e->getCode() ?: 500;
            return new ApiProblem($code, $e);
        }

        if ($collection instanceof ApiProblem) {
            return $collection;
        }

        $plugin = $this->plugin('HalLinks');
        /** @var \Laminas\Http\Request $request */
        $request = $this->getRequest();
        $collection = $plugin->createCollection($collection, $this->route);
        $collection->setCollectionRoute($this->route);
        $collection->setIdentifierName($this->getIdentifierName());
        $collection->setResourceRoute($this->route);
        $collection->setPage($request->getQuery('page', 1));
        $collection->setPageSize($this->pageSize);
        $collection->setCollectionName($this->collectionName);

        $events->trigger('patchList.post', $this, ['data' => $data, 'collection' => $collection]);
        return $collection;
    }

    /**
     * Delete an existing resource
     *
     * @param  int|string $id
     * @return Response|ApiProblem
     */
    public function delete($id)
    {
        if ($id && !$this->isMethodAllowedForResource()) {
            return $this->createMethodNotAllowedResponse($this->resourceHttpOptions);
        }
        if (!$id && !$this->isMethodAllowedForCollection()) {
            return $this->createMethodNotAllowedResponse($this->collectionHttpOptions);
        }

        $events = $this->getEventManager();
        $events->trigger('delete.pre', $this, ['id' => $id]);

        try {
            $result = $this->resource->delete($id);
        } catch (\Exception $e) {
            $code = (int) $e->getCode() ?: 500;

            return new ApiProblem($code, $e);
        }

        $result = $result ?: new ApiProblem(422, 'Unable to delete resource.');

        if ($result instanceof ApiProblem) {
            return $result;
        }

        /** @var \Laminas\Http\Response $response */
        $response = $this->getResponse();
        $response->setStatusCode(204);

        $events->trigger('delete.post', $this, ['id' => $id]);

        return $response;
    }

    /**
     * @param array $data
     * @return \Laminas\Http\Response|ApiProblem
     */
    public function deleteList($data = [])
    {
        if (!$this->isMethodAllowedForCollection()) {
            return $this->createMethodNotAllowedResponse($this->collectionHttpOptions);
        }

        $events = $this->getEventManager();
        $events->trigger('deleteList.pre', $this, []);

        try {
            $result = $this->resource->deleteList();
        } catch (\Exception $e) {
            $code = (int) $e->getCode() ?: 500;

            return new ApiProblem($code, $e);
        }

        $result = $result ?: new ApiProblem(422, 'Unable to delete collection.');

        if ($result instanceof ApiProblem) {
            return $result;
        }

        /** @var \Laminas\Http\Response $response */
        $response = $this->getResponse();
        $response->setStatusCode(204);

        $events->trigger('deleteList.post', $this, []);

        return $response;
    }

    /**
     * Return single resource
     *
     * @param  int|string $id
     * @return Response|ApiProblem|HalResource
     */
    public function get($id)
    {
        if (!$this->isMethodAllowedForResource()) {
            return $this->createMethodNotAllowedResponse($this->resourceHttpOptions);
        }

        $events = $this->getEventManager();
        $events->trigger('get.pre', $this, ['id' => $id]);

        try {
            $resource = $this->resource->fetch($id);
        } catch (\Exception $e) {
            $code = (int) $e->getCode() ?: 500;

            return new ApiProblem($code, $e);
        }

        $resource = $resource ?: new ApiProblem(404, 'Resource not found.');

        if ($resource instanceof ApiProblem) {
            return $resource;
        }

        $plugin   = $this->plugin('HalLinks');
        $resource = $plugin->createResource($resource, $this->route, $this->getIdentifierName());

        if ($resource instanceof ApiProblem) {
            return $resource;
        }

        $events->trigger('get.post', $this, ['id' => $id, 'resource' => $resource]);
        return $resource;
    }

    /**
     * Return collection of resources
     *
     * @return Response|HalCollection|ApiProblem
     */
    public function getList()
    {
        if (!$this->isMethodAllowedForCollection()) {
            return $this->createMethodNotAllowedResponse($this->collectionHttpOptions);
        }

        $events = $this->getEventManager();
        $events->trigger('getList.pre', $this, []);

        try {
            $collection = $this->resource->fetchAll();
        } catch (\Exception $e) {
            $code = (int) $e->getCode() ?: 500;

            return new ApiProblem($code, $e);
        }

        if ($collection instanceof ApiProblem) {
            return $collection;
        }

        $plugin     = $this->plugin('HalLinks');
        /** @var \Laminas\Http\Request $request */
        $request = $this->getRequest();
        $collection = $plugin->createCollection($collection, $this->route);
        $collection->setCollectionRoute($this->route);
        $collection->setIdentifierName($this->getIdentifierName());
        $collection->setResourceRoute($this->route);
        $collection->setPage($request->getQuery('page', 1));
        $collection->setCollectionName($this->collectionName);

        $pageSize = $this->pageSizeParam
            ? $request->getQuery($this->pageSizeParam, $this->pageSize)
            : $this->pageSize;
        $collection->setPageSize($pageSize);

        $events->trigger('getList.post', $this, ['collection' => $collection]);
        return $collection;
    }

    /**
     * Retrieve HEAD metadata for the resource and/or collection
     *
     * @param  null|mixed $id
     * @return Response|ApiProblem|HalResource|HalCollection
     */
    public function head($id = null)
    {
        if ($id) {
            return $this->get($id);
        }
        return $this->getList();
    }

    /**
     * Respond to OPTIONS request
     *
     * Uses $options to set the Allow header line and return an empty response.
     *
     * @return Response
     */
    public function options()
    {
        if (null === $id = $this->params()->fromRoute('id')) {
            $id = $this->params()->fromQuery('id');
        }

        if ($id) {
            $options = $this->resourceHttpOptions;
        } else {
            $options = $this->collectionHttpOptions;
        }

        array_walk(
            $options,
            /**
             * @param string $method
             * @return void
             */
            function (&$method): void {
                $method = strtoupper($method);
            }
        );

        $events = $this->getEventManager();
        $events->trigger('options.pre', $this, ['options' => $options]);

        /** @var \Laminas\Http\Response $response */
        $response = $this->getResponse();
        $response->setStatusCode(204);
        $headers  = $response->getHeaders();
        $headers->addHeaderLine('Allow', implode(', ', $options));

        $events->trigger('options.post', $this, ['options' => $options]);

        return $response;
    }

    /**
     * Respond to the PATCH method (partial update of existing resource)
     *
     * @param  int|string $id
     * @param  array $data
     * @return Response|ApiProblem|HalResource
     */
    public function patch($id, $data)
    {
        if (!$this->isMethodAllowedForResource()) {
            return $this->createMethodNotAllowedResponse($this->resourceHttpOptions);
        }

        $events = $this->getEventManager();
        $events->trigger('patch.pre', $this, ['id' => $id, 'data' => $data]);

        try {
            $resource = $this->resource->patch($id, $data);
        } catch (\Exception $e) {
            $code = (int) $e->getCode() ?: 500;
            return new ApiProblem($code, $e);
        }

        if ($resource instanceof ApiProblem) {
            return $resource;
        }

        $plugin   = $this->plugin('HalLinks');
        $resource = $plugin->createResource($resource, $this->route, $this->getIdentifierName());

        if ($resource instanceof ApiProblem) {
            return $resource;
        }

        $events->trigger('patch.post', $this, ['id' => $id, 'data' => $data, 'resource' => $resource]);
        return $resource;
    }

    /**
     * Update an existing resource
     *
     * @param  int|string $id
     * @param  array $data
     * @return Response|ApiProblem|HalResource
     */
    public function update($id, $data)
    {
        if ($id && !$this->isMethodAllowedForResource()) {
            return $this->createMethodNotAllowedResponse($this->resourceHttpOptions);
        }
        if (!$id && !$this->isMethodAllowedForCollection()) {
            return $this->createMethodNotAllowedResponse($this->collectionHttpOptions);
        }

        $events = $this->getEventManager();
        $events->trigger('update.pre', $this, ['id' => $id, 'data' => $data]);

        try {
            $resource = $this->resource->update($id, $data);
        } catch (\Exception $e) {
            $code = (int) $e->getCode() ?: 500;
            return new ApiProblem($code, $e);
        }

        if ($resource instanceof ApiProblem) {
            return $resource;
        }

        $plugin   = $this->plugin('HalLinks');
        $resource = $plugin->createResource($resource, $this->route, $this->getIdentifierName());

        $events->trigger('update.post', $this, ['id' => $id, 'data' => $data, 'resource' => $resource]);
        return $resource;
    }

    /**
     * Update an existing collection of resources
     *
     * @param array $data
     * @return array|ApiProblem|Response
     */
    public function replaceList($data)
    {
        if (!$this->isMethodAllowedForCollection()) {
            return $this->createMethodNotAllowedResponse($this->collectionHttpOptions);
        }

        $events = $this->getEventManager();
        $events->trigger('replaceList.pre', $this, ['data' => $data]);

        try {
            $collection = $this->resource->replaceList($data);
        } catch (\Exception $e) {
            $code = (int) $e->getCode() ?: 500;
            return new ApiProblem($code, $e);
        }

        if ($collection instanceof ApiProblem) {
            return $collection;
        }

        $plugin = $this->plugin('HalLinks');
        /** @var \Laminas\Http\Request $request */
        $request = $this->getRequest();
        $collection = $plugin->createCollection($collection, $this->route);
        $collection->setCollectionRoute($this->route);
        $collection->setIdentifierName($this->getIdentifierName());
        $collection->setResourceRoute($this->route);
        $collection->setPage($request->getQuery('page', 1));
        $collection->setPageSize($this->pageSize);
        $collection->setCollectionName($this->collectionName);

        $events->trigger('replaceList.post', $this, ['data' => $data, 'collection' => $collection]);
        return $collection;
    }

    /**
     * Retrieve the identifier, if any
     *
     * Attempts to see if an identifier was passed in either the URI or the
     * query string, returning it if found. Otherwise, returns a boolean false.
     *
     * @param  \Laminas\Router\RouteMatch $routeMatch
     * @param  \Laminas\Http\Request $request
     * @return false|mixed
     */
    protected function getIdentifier($routeMatch, $request)
    {
        $identifier = $this->getIdentifierName();
        $id = $routeMatch->getParam($identifier, false);
        if ($id !== false) {
            return $id;
        }

        $id = $request->getQuery()->get($identifier, false);
        if ($id !== false) {
            return $id;
        }

        return false;
    }

    /**
     * Is the current HTTP method allowed for a resource?
     *
     * @return bool
     */
    protected function isMethodAllowedForResource()
    {
        array_walk(
            $this->resourceHttpOptions,
            /**
             * @param string $method
             * @return void
             */
            function (&$method): void {
                $method = strtoupper($method);
            }
        );
        $options = array_merge($this->resourceHttpOptions, ['OPTIONS', 'HEAD']);
        /** @var \Laminas\Http\Request $request */
        $request = $this->getRequest();
        $method  = strtoupper($request->getMethod());
        if (!in_array($method, $options)) {
            return false;
        }
        return true;
    }

    /**
     * Is the current HTTP method allowed for the resource (collection)?
     *
     * @return bool
     */
    protected function isMethodAllowedForCollection()
    {
        array_walk(
            $this->collectionHttpOptions,
            /**
             * @param string $method
             * @return void
             */
            function (&$method): void {
                $method = strtoupper($method);
            }
        );
        $options = array_merge($this->collectionHttpOptions, ['OPTIONS', 'HEAD']);
        /** @var \Laminas\Http\Request $request */
        $request = $this->getRequest();
        $method  = strtoupper($request->getMethod());
        if (!in_array($method, $options)) {
            return false;
        }
        return true;
    }

    /**
     * Creates a "405 Method Not Allowed" response detailing the available options
     *
     * @param  array $options
     * @return Response
     */
    protected function createMethodNotAllowedResponse(array $options)
    {
        /** @var \Laminas\Http\Response $response */
        $response = $this->getResponse();
        $response->setStatusCode(405);
        $headers = $response->getHeaders();
        $headers->addHeaderLine('Allow', implode(', ', $options));
        return $response;
    }
}
