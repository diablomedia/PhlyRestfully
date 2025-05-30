<?php
/**
 * @link      https://github.com/weierophinney/PhlyRestfully for the canonical source repository
 * @copyright Copyright (c) 2013 Matthew Weier O'Phinney
 * @license   http://opensource.org/licenses/BSD-2-Clause BSD-2-Clause
 * @package   PhlyRestfully
 */

namespace PhlyRestfully\Listener;

use PhlyRestfully\ResourceController;
use Laminas\EventManager\EventManagerInterface;
use Laminas\EventManager\ListenerAggregateInterface;
use Laminas\EventManager\SharedEventManagerInterface;
use Laminas\Mvc\MvcEvent;

class ResourceParametersListener implements ListenerAggregateInterface
{
    /**
     * @var callable[]
     */
    protected $listeners = [];

    /**
     * @var callable[]
     */
    protected $sharedListeners = [];

    /**
     * @param EventManagerInterface $events
     * @param int $priority
     * @return void
     */
    public function attach(EventManagerInterface $events, $priority = 1): void
    {
        $this->listeners[] = $events->attach('dispatch', [$this, 'onDispatch'], 100);
    }

    /**
     * @param EventManagerInterface $events
     * @return void
     */
    public function detach(EventManagerInterface $events): void
    {
        foreach ($this->listeners as $index => $listener) {
            $events->detach($listener);
            unset($this->listeners[$index]);
        }
    }

    /**
     * @param SharedEventManagerInterface $events
     *
     * @return void
     */
    public function attachShared(SharedEventManagerInterface $events): void
    {
        $this->sharedListeners[] = $events->attach(ResourceController::class, 'dispatch', [$this, 'onDispatch'], 100);
    }

    /**
     * @param SharedEventManagerInterface $events
     *
     * @return void
     */
    public function detachShared(SharedEventManagerInterface $events): void
    {
        // Vary detachment based on zend-eventmanager version.
        $detach = /**
         * @param callable $listener
         * @return bool
         */
        function ($listener) use ($events) {
            try {
                $events->detach($listener, ResourceController::class);
                return true;
            } catch (\Exception $e) {
                return false;
            }
        };

        foreach ($this->sharedListeners as $index => $listener) {
            if ($detach($listener)) {
                unset($this->sharedListeners[$index]);
            }
        }
    }

    /**
     * Listen to the dispatch event
     *
     * @param MvcEvent $e
     *
     * @return void
     */
    public function onDispatch(MvcEvent $e): void
    {
        $controller = $e->getTarget();
        if (!$controller instanceof ResourceController) {
            return;
        }

        /** @var \Laminas\Http\Request $request */
        $request  = $e->getRequest();
        $query    = $request->getQuery();
        $matches  = $e->getRouteMatch();
        $resource = $controller->getResource();
        $resource->setQueryParams($query);
        $resource->setRouteMatch($matches);
    }
}
