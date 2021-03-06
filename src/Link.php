<?php
/**
 * @link      https://github.com/weierophinney/PhlyRestfully for the canonical source repository
 * @copyright Copyright (c) 2013 Matthew Weier O'Phinney
 * @license   http://opensource.org/licenses/BSD-2-Clause BSD-2-Clause
 * @package   PhlyRestfully
 */

namespace PhlyRestfully;

use Traversable;
use Laminas\Stdlib\ArrayUtils;
use Laminas\Uri\Exception as UriException;
use Laminas\Uri\UriFactory;

/**
 * Object describing a link relation
 */
class Link
{
    /**
     * @var string
     */
    protected $relation;

    /**
     * @var string
     */
    protected $route;

    /**
     * @var array
     */
    protected $routeOptions = [];

    /**
     * @var array
     */
    protected $routeParams = [];

    /**
     * @var string
     */
    protected $url;

    /**
     * Create a link relation
     *
     * @todo  filtering and/or validation of relation string
     */
    public function __construct(string $relation)
    {
        $this->relation = $relation;
    }

    /**
     * Factory for creating links
     *
     * @param  array $spec
     * @return self
     * @throws Exception\InvalidArgumentException if missing a "rel" or invalid route specifications
     */
    public static function factory(array $spec)
    {
        if (!isset($spec['rel'])) {
            throw new Exception\InvalidArgumentException(sprintf(
                '%s requires that the specification array contain a "rel" element; none found',
                __METHOD__
            ));
        }
        $link = new static($spec['rel']);

        if (isset($spec['url'])) {
            $link->setUrl($spec['url']);
            return $link;
        }

        if (isset($spec['route'])) {
            $routeInfo = $spec['route'];
            if (is_string($routeInfo)) {
                $link->setRoute($routeInfo);
                return $link;
            }

            if (!is_array($routeInfo)) {
                throw new Exception\InvalidArgumentException(sprintf(
                    '%s requires that the specification array\'s "route" element be a string or array; received "%s"',
                    __METHOD__,
                    (is_object($routeInfo) ? get_class($routeInfo) : gettype($routeInfo))
                ));
            }

            if (!isset($routeInfo['name'])) {
                throw new Exception\InvalidArgumentException(sprintf(
                    '%s requires that the specification array\'s "route" array contain a "name" element; none found',
                    __METHOD__
                ));
            }
            $name    = $routeInfo['name'];
            $params  = isset($routeInfo['params']) && is_array($routeInfo['params']) ? $routeInfo['params'] : [];
            $options = isset($routeInfo['options']) && is_array($routeInfo['options']) ? $routeInfo['options'] : [];
            $link->setRoute($name, $params, $options);
            return $link;
        }

        return $link;
    }

    /**
     * Set the route to use when generating the relation URI
     *
     * If any params or options are passed, those will be passed to route assembly.
     *
     * @param  null|array|Traversable $params
     * @param  null|array|Traversable $options
     * @return self
     */
    public function setRoute(string $route, $params = null, $options = null)
    {
        if ($this->hasUrl()) {
            throw new Exception\DomainException(sprintf(
                '%s already has a URL set; cannot set route',
                __CLASS__
            ));
        }

        $this->route = $route;
        if ($params) {
            $this->setRouteParams($params);
        }
        if ($options) {
            $this->setRouteOptions($options);
        }
        return $this;
    }

    /**
     * Set route assembly options
     *
     * @param  array|Traversable $options
     * @return self
     */
    public function setRouteOptions($options)
    {
        if ($options instanceof Traversable) {
            $options = ArrayUtils::iteratorToArray($options);
        }

        if (!is_array($options)) {
            throw new Exception\InvalidArgumentException(sprintf(
                '%s expects an array or Traversable; received "%s"',
                __METHOD__,
                (is_object($options) ? get_class($options) : gettype($options))
            ));
        }

        $this->routeOptions = $options;
        return $this;
    }

    /**
     * Set route assembly parameters/substitutions
     *
     * @param  array|Traversable $params
     * @return self
     */
    public function setRouteParams($params)
    {
        if ($params instanceof Traversable) {
            $params = ArrayUtils::iteratorToArray($params);
        }

        if (!is_array($params)) {
            throw new Exception\InvalidArgumentException(sprintf(
                '%s expects an array or Traversable; received "%s"',
                __METHOD__,
                (is_object($params) ? get_class($params) : gettype($params))
            ));
        }

        $this->routeParams = $params;
        return $this;
    }

    /**
     * Set an explicit URL for the link relation
     *
     * @param  string $url
     * @return self
     */
    public function setUrl($url)
    {
        if ($this->hasRoute()) {
            throw new Exception\DomainException(sprintf(
                '%s already has a route set; cannot set URL',
                __CLASS__
            ));
        }

        try {
            $uri = UriFactory::factory($url);
        } catch (UriException\ExceptionInterface $e) {
            throw new Exception\InvalidArgumentException(sprintf(
                'Received invalid URL: %s',
                $e->getMessage()
            ), (int) $e->getCode(), $e);
        }

        if (!$uri->isValid()) {
            throw new Exception\InvalidArgumentException(
                'Received invalid URL'
            );
        }

        $this->url = $uri->toString();
        return $this;
    }

    /**
     * Retrieve the link relation
     *
     * @return string
     */
    public function getRelation()
    {
        return $this->relation;
    }

    /**
     * Return the route to be used to generate the link URL, if any
     *
     * @return string
     */
    public function getRoute()
    {
        return $this->route;
    }

    /**
     * Retrieve route assembly options, if any
     *
     * @return array
     */
    public function getRouteOptions()
    {
        return $this->routeOptions;
    }

    /**
     * Retrieve route assembly parameters/substitutions, if any
     *
     * @return array
     */
    public function getRouteParams()
    {
        return $this->routeParams;
    }

    /**
     * Retrieve the link URL, if set
     *
     * @return string
     */
    public function getUrl()
    {
        return $this->url;
    }

    /**
     * Is the link relation complete -- do we have either a URL or a route set?
     *
     * @return bool
     */
    public function isComplete()
    {
        return (!empty($this->url) || !empty($this->route));
    }

    /**
     * Does the link have a route set?
     *
     * @return bool
     */
    public function hasRoute()
    {
        return !empty($this->route);
    }

    /**
     * Does the link have a URL set?
     *
     * @return bool
     */
    public function hasUrl()
    {
        return !empty($this->url);
    }
}
