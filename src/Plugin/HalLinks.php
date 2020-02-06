<?php
/**
 * @link      https://github.com/weierophinney/PhlyRestfully for the canonical source repository
 * @copyright Copyright (c) 2013 Matthew Weier O'Phinney
 * @license   http://opensource.org/licenses/BSD-2-Clause BSD-2-Clause
 * @package   PhlyRestfully
 */

namespace PhlyRestfully\Plugin;

use ArrayObject;
use PhlyRestfully\ApiProblem;
use PhlyRestfully\Exception;
use PhlyRestfully\HalCollection;
use PhlyRestfully\HalResource;
use PhlyRestfully\Link;
use PhlyRestfully\LinkCollection;
use PhlyRestfully\LinkCollectionAwareInterface;
use PhlyRestfully\Metadata;
use PhlyRestfully\MetadataMap;
use Laminas\EventManager\Event;
use Laminas\EventManager\EventManager;
use Laminas\EventManager\EventManagerAwareInterface;
use Laminas\EventManager\EventManagerInterface;
use Laminas\Mvc\Controller\Plugin\PluginInterface as ControllerPluginInterface;
use Laminas\Paginator\Paginator;
use Laminas\Stdlib\ArrayUtils;
use Laminas\Stdlib\DispatchableInterface;
use Laminas\Hydrator\HydratorInterface;
use Laminas\Hydrator\HydratorPluginManager;
use Laminas\View\Helper\AbstractHelper;
use Laminas\View\Helper\ServerUrl;
use Laminas\View\Helper\Url;

/**
 * Generate links for use with HAL payloads
 */
class HalLinks extends AbstractHelper implements
    ControllerPluginInterface,
    EventManagerAwareInterface
{
    /**
     * @var DispatchableInterface
     */
    protected $controller;

    /**
     * Default hydrator to use if no hydrator found for a specific resource class.
     *
     * @var HydratorInterface
     */
    protected $defaultHydrator;

    /**
     * @var EventManagerInterface
     */
    protected $events;

    /**
     * Map of class name/(hydrator instance|name) pairs
     *
     * @var array
     */
    protected $hydratorMap = [];

    /**
     * @var HydratorPluginManager
     */
    protected $hydrators;

    /**
     * @var MetadataMap
     */
    protected $metadataMap;

    /**
     * @var ServerUrl
     */
    protected $serverUrlHelper;

    /**
     * @var Url
     */
    protected $urlHelper;

    /**
     * @param null|HydratorPluginManager $hydrators
     * @return void
     */
    public function __construct(HydratorPluginManager $hydrators = null)
    {
        if (null === $hydrators) {
            $hydrators = new HydratorPluginManager();
        }
        $this->hydrators = $hydrators;
    }

    /**
     * @param DispatchableInterface $controller
     * @return void
     */
    public function setController(DispatchableInterface $controller)
    {
        $this->controller = $controller;
    }

    /**
     * @return DispatchableInterface
     */
    public function getController()
    {
        return $this->controller;
    }

    /**
     * Retrieve the event manager instance
     *
     * Lazy-initializes one if none present.
     *
     * @return EventManagerInterface
     */
    public function getEventManager()
    {
        if (!$this->events) {
            $this->setEventManager(new EventManager());
        }
        return $this->events;
    }

    /**
     * Set the event manager instance
     *
     * @param  EventManagerInterface $events
     * @return void
     */
    public function setEventManager(EventManagerInterface $events)
    {
        $events->setIdentifiers([
            __CLASS__,
            get_class($this),
        ]);
        $this->events = $events;

        $events->attach(
            'getIdFromResource',
            /**
             * @param \Laminas\Mvc\MvcEvent $e
             * @return mixed|false
             */
            function ($e) {
                $resource = $e->getParam('resource');
                $identifierName = $e->getParam('identifier_name', 'id');

                // Found id in array
                if (is_array($resource) && array_key_exists($identifierName, $resource)) {
                    return $resource[$identifierName];
                }

                // No id in array, or not an object; return false
                if (is_array($resource) || !is_object($resource)) {
                    return false;
                }

                // Found public id property on object
                if (isset($resource->{$identifierName})) {
                    return $resource->{$identifierName};
                }

                $publicGetter = 'get' . ucfirst($identifierName);

                // Found public id getter on object
                if (method_exists($resource, $publicGetter)) {
                    return $resource->{$publicGetter}();
                }

                // not found
                return false;
            }
        );
    }

    /**
     * @return HydratorPluginManager
     */
    public function getHydratorManager()
    {
        return $this->hydrators;
    }

    /**
     * Retrieve the metadata map
     *
     * @return MetadataMap
     */
    public function getMetadataMap()
    {
        if (!$this->metadataMap instanceof MetadataMap) {
            $this->setMetadataMap(new MetadataMap());
        }
        return $this->metadataMap;
    }

    /**
     * Set the metadata map
     *
     * @param  MetadataMap $map
     * @return self
     */
    public function setMetadataMap(MetadataMap $map)
    {
        $this->metadataMap = $map;
        return $this;
    }

    /**
     * @param ServerUrl $helper
     * @return void
     */
    public function setServerUrlHelper(ServerUrl $helper)
    {
        $this->serverUrlHelper = $helper;
    }

    /**
     * @param Url $helper
     * @return void
     */
    public function setUrlHelper(Url $helper)
    {
        $this->urlHelper = $helper;
    }

    /**
     * Map a resource class to a specific hydrator instance
     *
     * @param  string $class
     * @param  HydratorInterface|string $hydrator
     * @return $this
     */
    public function addHydrator($class, $hydrator)
    {
        if (!$hydrator instanceof HydratorInterface) {
            if (!$this->hydrators->has((string) $hydrator)) {
                throw new Exception\InvalidArgumentException(sprintf(
                    'Invalid hydrator instance or name provided; received "%s"',
                    (
                        is_object($hydrator)
                        ? get_class($hydrator)
                        : (is_string($hydrator) ? $hydrator : gettype($hydrator))
                    )
                ));
            }
            $hydrator = $this->hydrators->get($hydrator);
        }
        $class = strtolower($class);
        $this->hydratorMap[$class] = $hydrator;
        return $this;
    }

    /**
     * Set the default hydrator to use if none specified for a class.
     *
     * @param  HydratorInterface $hydrator
     * @return $this
     */
    public function setDefaultHydrator(HydratorInterface $hydrator)
    {
        $this->defaultHydrator = $hydrator;
        return $this;
    }

    /**
     * Retrieve a hydrator for a given resource
     *
     * If the resource has a mapped hydrator, returns that hydrator. If not, and
     * a default hydrator is present, the default hydrator is returned.
     * Otherwise, a boolean false is returned.
     *
     * @param  object $resource
     * @return HydratorInterface|false
     */
    public function getHydratorForResource($resource)
    {
        $metadataMap = $this->getMetadataMap();
        if ($metadataMap->has($resource)) {
            $metadata = $metadataMap->get($resource);
            $hydrator = $metadata->getHydrator();
            if ($hydrator instanceof HydratorInterface) {
                return $hydrator;
            }
        }

        $class = strtolower(get_class($resource));
        if (isset($this->hydratorMap[$class])) {
            return $this->hydratorMap[$class];
        }

        if ($this->defaultHydrator instanceof HydratorInterface) {
            return $this->defaultHydrator;
        }

        return false;
    }

    /**
     * "Render" a HalCollection
     *
     * Injects pagination links, if the composed collection is a Paginator, and
     * then loops through the collection to create the data structure representing
     * the collection.
     *
     * For each resource in the collection, the event "renderCollection.resource" is
     * triggered, with the following parameters:
     *
     * - "collection", which is the $halCollection passed to the method
     * - "resource", which is the current resource
     * - "route", the resource route that will be used to generate links
     * - "routeParams", any default routing parameters/substitutions to use in URL assembly
     * - "routeOptions", any default routing options to use in URL assembly
     *
     * This event can be useful particularly when you have multi-segment routes
     * and wish to ensure that route parameters are injected, or if you want to
     * inject query or fragment parameters.
     *
     * Event parameters are aggregated in an ArrayObject, which allows you to
     * directly manipulate them in your listeners:
     *
     * <code>
     * $params = $e->getParams();
     * $params['routeOptions']['query'] = array('format' => 'json');
     * </code>
     *
     * @param  HalCollection $halCollection
     * @return array|ApiProblem Associative array representing the payload to
     *     render; returns ApiProblem if error in pagination occurs
     */
    public function renderCollection(HalCollection $halCollection)
    {
        $this->getEventManager()->trigger(__FUNCTION__, $this, ['collection' => $halCollection]);
        $collection     = $halCollection->collection;
        $collectionName = $halCollection->collectionName;

        if ($collection instanceof Paginator) {
            $status = $this->injectPaginationLinks($halCollection);
            if ($status instanceof ApiProblem) {
                return $status;
            }
        }

        $payload = $halCollection->attributes;
        $payload['_links']    = $this->fromResource($halCollection);
        $payload['_embedded'] = [
            $collectionName => $this->extractCollection($halCollection),
        ];

        return $payload;
    }

    /**
     * Render an individual resource
     *
     * Creates a hash representation of the HalResource. The resource is first
     * converted to an array, and its associated links are injected as the
     * "_links" member. If any members of the resource are themselves
     * HalResource objects, they are extracted into an "_embedded" hash.
     *
     * @param  HalResource $halResource
     * @return array
     */
    public function renderResource(HalResource $halResource)
    {
        $this->getEventManager()->trigger(__FUNCTION__, $this, ['resource' => $halResource]);
        $resource = $halResource->resource;
        $links    = $this->fromResource($halResource);

        if (!is_array($resource)) {
            $resource = $this->convertResourceToArray($resource);
        }

        $metadataMap = $this->getMetadataMap();
        foreach ($resource as $key => $value) {
            if (is_object($value) && $metadataMap->has($value)) {
                $value = $this->createResourceFromMetadata($value, $metadataMap->get($value));
            }

            if ($value instanceof HalResource) {
                $this->extractEmbeddedHalResource($resource, $key, $value);
            }
            if ($value instanceof HalCollection) {
                $this->extractEmbeddedHalCollection($resource, $key, $value);
            }
        }

        $resource['_links'] = $links;

        return $resource;
    }

    /**
     * Create a fully qualified URI for a link
     *
     * Triggers the "createLink" event with the route, id, resource, and a set of
     * params that will be passed to the route; listeners can alter any of the
     * arguments, which will then be used by the method to generate the url.
     *
     * @param  string $route
     * @param  null|false|int|string $id
     * @param  null|mixed $resource
     * @return string
     */
    public function createLink($route, $id = null, $resource = null)
    {
        $params             = new ArrayObject();
        $reUseMatchedParams = true;

        if (false === $id) {
            $reUseMatchedParams = false;
        } elseif (null !== $id) {
            $params['id'] = $id;
        }

        /** @var \Laminas\EventManager\EventManager $events */
        $events      = $this->getEventManager();
        $eventParams = $events->prepareArgs([
            'route'    => $route,
            'id'       => $id,
            'resource' => $resource,
            'params'   => $params,
        ]);
        $events->trigger(__FUNCTION__, $this, $eventParams);
        $route = $eventParams['route'];

        $path = call_user_func($this->urlHelper, $route, $params->getArrayCopy(), $reUseMatchedParams);

        if (substr($path, 0, 4) == 'http') {
            return $path;
        }

        return call_user_func($this->serverUrlHelper, $path);
    }

    /**
     * Create a URL from a Link
     *
     * @param Link $linkDefinition
     *
     * @return array
     *
     * @throws Exception\DomainException if Link is incomplete
     *
     * @psalm-return array{href:mixed}
     */
    public function fromLink(Link $linkDefinition)
    {
        if (!$linkDefinition->isComplete()) {
            throw new Exception\DomainException(sprintf(
                'Link from resource provided to %s was incomplete; must contain a URL or a route',
                __METHOD__
            ));
        }

        if ($linkDefinition->hasUrl()) {
            return [
                'href' => $linkDefinition->getUrl(),
            ];
        }

        $reuseMatchedParams = true;
        $options = $linkDefinition->getRouteOptions();
        if (isset($options['reuse_matched_params'])) {
            $reuseMatchedParams = (bool) $options['reuse_matched_params'];
            unset($options['reuse_matched_params']);
        }

        $path = call_user_func(
            $this->urlHelper,
            $linkDefinition->getRoute(),
            $linkDefinition->getRouteParams(),
            $options,
            $reuseMatchedParams
        );

        if (substr($path, 0, 4) == 'http') {
            return [
                'href' => $path,
            ];
        }

        return [
            'href' => call_user_func($this->serverUrlHelper, $path),
        ];
    }

    /**
     * Generate HAL links from a LinkCollection
     *
     * @param LinkCollection $collection
     *
     * @return array[]
     *
     * @psalm-return array<mixed, array>
     */
    public function fromLinkCollection(LinkCollection $collection)
    {
        $links = [];
        foreach ($collection as $rel => $linkDefinition) {
            if ($linkDefinition instanceof Link) {
                $links[$rel] = $this->fromLink($linkDefinition);
                continue;
            }
            if (!is_array($linkDefinition)) {
                throw new Exception\DomainException(sprintf(
                    'Link object for relation "%s" in resource was malformed; cannot generate link',
                    $rel
                ));
            }

            $aggregate = [];
            foreach ($linkDefinition as $subLink) {
                if (!$subLink instanceof Link) {
                    throw new Exception\DomainException(sprintf(
                        'Link object aggregated for relation "%s" in resource was malformed; cannot generate link',
                        $rel
                    ));
                }
                $aggregate[] = $this->fromLink($subLink);
            }
            $links[$rel] = $aggregate;
        }
        return $links;
    }

    /**
     * Create HAL links "object" from a resource/collection
     *
     * @param  LinkCollectionAwareInterface $resource
     * @return array
     */
    public function fromResource(LinkCollectionAwareInterface $resource)
    {
        return $this->fromLinkCollection($resource->getLinks());
    }

    /**
     * Create a resource and/or collection based on a metadata map
     *
     * @param  object $object
     * @param  Metadata $metadata
     * @return HalResource|HalCollection
     */
    public function createResourceFromMetadata($object, Metadata $metadata)
    {
        if ($metadata->isCollection()) {
            return $this->createCollectionFromMetadata($object, $metadata);
        }

        if ($metadata->hasHydrator()) {
            $hydrator = $metadata->getHydrator();
        } else {
            $hydrator = $this->getHydratorForResource($object);
        }
        if (!$hydrator instanceof HydratorInterface) {
            throw new Exception\RuntimeException(sprintf(
                'Unable to extract %s; no hydrator registered',
                get_class($object)
            ));
        }
        $data = $hydrator->extract($object);

        $identifierName = $metadata->getIdentifierName();

        if (false !== $identifierName && ! isset($data[$identifierName])) {
            throw new Exception\RuntimeException(sprintf(
                'Unable to determine identifier for object of type "%s"; no fields matching "%s"',
                get_class($object),
                $identifierName
            ));
        }

        $id = (false === $identifierName) ? null : $data[$identifierName];

        $resource = new HalResource($data, $id);
        $links    = $resource->getLinks();
        $this->marshalMetadataLinks($metadata, $links);
        if (!$links->has('self')) {
            $link = $this->marshalSelfLinkFromMetadata($metadata, $object, $id, $identifierName);
            $links->add($link);
        }

        return $resource;
    }

    /**
     * Create a HalResource instance and inject it with a self relational link
     *
     * @param HalResource|array|object $resource
     * @param string $route
     * @param string $identifierName
     *
     * @return HalResource|ApiProblem
     */
    public function createResource($resource, $route, $identifierName)
    {
        $metadataMap = $this->getMetadataMap();
        if (is_object($resource) && $metadataMap->has($resource)) {
            $resource = $this->createResourceFromMetadata($resource, $metadataMap->get($resource));
        }

        if (!$resource instanceof HalResource) {
            $id = $this->getIdFromResource($resource, $identifierName);
            if (!$id) {
                return new ApiProblem(
                    422,
                    'No resource identifier present following resource creation.'
                );
            }
            $resource = new HalResource($resource, $id);
        }

        $this->injectSelfLink($resource, $route, $identifierName);
        return $resource;
    }

    /**
     * Creates a HalCollection instance with a self relational link
     *
     * @param  HalCollection|array|object $collection
     * @param  string $route
     * @return HalCollection
     */
    public function createCollection($collection, $route)
    {
        $metadataMap = $this->getMetadataMap();
        if (is_object($collection) && $metadataMap->has($collection)) {
            $collection = $this->createCollectionFromMetadata($collection, $metadataMap->get($collection));
        }

        if (!$collection instanceof HalCollection) {
            $collection = new HalCollection($collection);
        }

        $this->injectSelfLink($collection, $route);
        return $collection;
    }

    /**
     * @param  object $object
     * @param  Metadata $metadata
     * @return HalCollection
     */
    public function createCollectionFromMetadata($object, Metadata $metadata)
    {
        $collection = new HalCollection($object);
        $collection->setCollectionRoute($metadata->getRoute());
        $collection->setResourceRoute($metadata->getResourceRoute());
        $collection->setIdentifierName($metadata->getIdentifierName());
        $this->marshalMetadataLinks($metadata, $collection->getLinks());
        return $collection;
    }

    /**
     * Inject a "self" relational link based on the route and identifier
     *
     * @param  LinkCollectionAwareInterface $resource
     * @param  string $route
     * @param  string $identifier
     * @return void
     */
    public function injectSelfLink(LinkCollectionAwareInterface $resource, $route, $identifier = 'id')
    {
        $self = new Link('self');
        $self->setRoute($route);
        if ($resource instanceof HalResource) {
            $self->setRouteParams([$identifier => $resource->id]);
        }
        $resource->getLinks()->add($self, true);
    }

    /**
     * Generate HAL links for a paginated collection
     *
     * @param HalCollection $halCollection
     *
     * @return true|ApiProblem
     */
    protected function injectPaginationLinks(HalCollection $halCollection)
    {
        $collection = $halCollection->collection;
        $page       = $halCollection->page;
        $pageSize   = $halCollection->pageSize;
        $route      = $halCollection->collectionRoute;
        $params     = $halCollection->collectionRouteParams;
        $options    = $halCollection->collectionRouteOptions;

        $collection->setItemCountPerPage($pageSize);
        $collection->setCurrentPageNumber($page);

        $count = count($collection);
        if (!$count) {
            return true;
        }

        if ($page < 1 || $page > $count) {
            return new ApiProblem(409, 'Invalid page provided');
        }

        $links = $halCollection->getLinks();
        $next  = ($page < $count) ? $page + 1 : false;
        $prev  = ($page > 1)      ? $page - 1 : false;

        // self link
        $link = new Link('self');
        $link->setRoute($route);
        $link->setRouteParams($params);
        $link->setRouteOptions(ArrayUtils::merge(
            $options,
            [
            'query' => ['page' => $page]]
        ));
        $links->add($link, true);

        // first link
        $link = new Link('first');
        $link->setRoute($route);
        $link->setRouteParams($params);
        $link->setRouteOptions($options);
        $links->add($link);

        // last link
        $link = new Link('last');
        $link->setRoute($route);
        $link->setRouteParams($params);
        $link->setRouteOptions(ArrayUtils::merge(
            $options,
            [
            'query' => ['page' => $count]]
        ));
        $links->add($link);

        // prev link
        if ($prev) {
            $link = new Link('prev');
            $link->setRoute($route);
            $link->setRouteParams($params);
            $link->setRouteOptions(ArrayUtils::merge(
                $options,
                [
                'query' => ['page' => $prev]]
            ));
            $links->add($link);
        }

        // next link
        if ($next) {
            $link = new Link('next');
            $link->setRoute($route);
            $link->setRouteParams($params);
            $link->setRouteOptions(ArrayUtils::merge(
                $options,
                [
                'query' => ['page' => $next]]
            ));
            $links->add($link);
        }

        return true;
    }

    /**
     * Extracts and renders a HalResource and embeds it in the parent
     * representation
     *
     * Removes the key from the parent representation, and creates a
     * representation for the key in the _embedded object.
     *
     * @param  array $parent
     * @param  string $key
     * @param  HalResource $resource
     * @return void
     */
    protected function extractEmbeddedHalResource(array &$parent, $key, HalResource $resource)
    {
        $rendered = $this->renderResource($resource);
        if (!isset($parent['_embedded'])) {
            $parent['_embedded'] = [];
        }
        $parent['_embedded'][$key] = $rendered;
        unset($parent[$key]);
    }

    /**
     * Extracts and renders a HalCollection and embeds it in the parent
     * representation
     *
     * Removes the key from the parent representation, and creates a
     * representation for the key in the _embedded object.
     *
     * @param  array $parent
     * @param  string $key
     * @param  HalCollection $collection
     * @return void
     */
    protected function extractEmbeddedHalCollection(array &$parent, $key, HalCollection $collection)
    {
        $rendered = $this->extractCollection($collection);
        if (!isset($parent['_embedded'])) {
            $parent['_embedded'] = [];
        }
        $parent['_embedded'][$key] = $rendered;
        unset($parent[$key]);
    }

    /**
     * Extract a collection as an array
     *
     * @param HalCollection $halCollection
     *
     * @return array
     *
     * @psalm-return array<int, mixed>
     */
    protected function extractCollection(HalCollection $halCollection)
    {
        $collection           = [];
        $events               = $this->getEventManager();
        $identifierName       = $halCollection->identifierName;
        $resourceRoute        = $halCollection->resourceRoute;
        $resourceRouteParams  = $halCollection->resourceRouteParams;
        $resourceRouteOptions = $halCollection->resourceRouteOptions;
        $metadataMap          = $this->getMetadataMap();

        foreach ($halCollection->collection as $resource) {
            $eventParams = new ArrayObject([
                'collection'   => $halCollection,
                'resource'     => $resource,
                'route'        => $resourceRoute,
                'routeParams'  => $resourceRouteParams,
                'routeOptions' => $resourceRouteOptions,
            ]);
            $events->trigger('renderCollection.resource', $this, $eventParams);

            $resource = $eventParams['resource'];

            if ($resource instanceof HalResource) {
                $collection[] = $this->renderResource($resource);
                continue;
            }

            if (!is_array($resource)) {
                $resource = $this->convertResourceToArray($resource);
            }

            foreach ($resource as $key => $value) {
                if (is_object($value) && $metadataMap->has($value)) {
                    $value = $this->createResourceFromMetadata($value, $metadataMap->get($value));
                }

                if ($value instanceof HalResource) {
                    $this->extractEmbeddedHalResource($resource, $key, $value);
                }
                if ($value instanceof HalCollection) {
                    $this->extractEmbeddedHalCollection($resource, $key, $value);
                }
            }

            $id = $this->getIdFromResource($resource, $identifierName);
            if (!$id) {
                // Cannot handle resources without an identifier
                // Return as-is
                $collection[] = $resource;
                continue;
            }

            if ($eventParams['resource'] instanceof LinkCollectionAwareInterface) {
                $links = $eventParams['resource']->getLinks();
            } else {
                $links = new LinkCollection();
            }

            $selfLink = new Link('self');
            $selfLink->setRoute(
                $eventParams['route'],
                array_merge($eventParams['routeParams'], [$identifierName => $id]),
                $eventParams['routeOptions']
            );
            $links->add($selfLink);

            $resource['_links'] = $this->fromLinkCollection($links);

            $collection[] = $resource;
        }

        return $collection;
    }

    /**
     * Retrieve the identifier from a resource
     *
     * Expects an "id" or $identifierName member to exist; if not, a boolean false is returned.
     *
     * Triggers the "getIdFromResource" event with the resource; listeners can
     * return a non-false, non-null value in order to specify the identifier
     * to use for URL assembly.
     *
     * @param  array|object $resource
     * @param  null|string  $identifierName
     * @return mixed|false
     */
    protected function getIdFromResource($resource, $identifierName = null)
    {
        $params = ['resource' => $resource];
        if ($identifierName) {
            $params['identifier_name'] = $identifierName;
        }

        $event = new Event(__FUNCTION__, $this, $params);
        /** @var \Laminas\EventManager\EventManager $em */
        $em = $this->getEventManager();
        $results = $em->triggerEventUntil(
            /**
             * @param mixed $r
             * @return bool
             */
            function ($r) {
                return (null !== $r && false !== $r);
            },
            $event
        );

        if ($results->stopped()) {
            return $results->last();
        }

        return false;
    }

    /**
     * Convert an individual resource to an array
     *
     * @param  object $resource
     * @return array
     */
    protected function convertResourceToArray($resource)
    {
        $hydrator = $this->getHydratorForResource($resource);
        if (!$hydrator) {
            return (array) $resource;
        }

        return $hydrator->extract($resource);
    }

    /**
     * Creates a link object, given metadata and a resource
     *
     * @param  Metadata $metadata
     * @param  object $object
     * @param  string $id
     * @param  string $identifierName
     * @return Link
     * @throws Exception\RuntimeException
     */
    protected function marshalSelfLinkFromMetadata(Metadata $metadata, $object, $id, $identifierName)
    {
        $link = new Link('self');
        if ($metadata->hasUrl()) {
            $link->setUrl($metadata->getUrl());
            return $link;
        }

        if (!$metadata->hasRoute()) {
            throw new Exception\RuntimeException(sprintf(
                'Unable to create a self link for resource of type "%s"; metadata does not contain a route or a url',
                get_class($object)
            ));
        }

        $params = array_merge($metadata->getRouteParams(), [$identifierName => $id]);
        $link->setRoute($metadata->getRoute(), $params, $metadata->getRouteOptions());
        return $link;
    }

    /**
     * Inject any links found in the metadata into the resource's link collection
     *
     * @param  Metadata $metadata
     * @param  LinkCollection $links
     * @return void
     */
    protected function marshalMetadataLinks(Metadata $metadata, LinkCollection $links)
    {
        foreach ($metadata->getLinks() as $linkData) {
            $link = Link::factory($linkData);
            $links->add($link);
        }
    }
}
