<?php
/**
 * @link      https://github.com/weierophinney/PhlyRestfully for the canonical source repository
 * @copyright Copyright (c) 2013 Matthew Weier O'Phinney
 * @license   http://opensource.org/licenses/BSD-2-Clause BSD-2-Clause
 * @package   PhlyRestfully
 */

namespace PhlyRestfullyTest\Factory;

use PhlyRestfully\ResourceController;
use PhlyRestfully\Factory\ResourceControllerFactory;
use PHPUnit_Framework_TestCase as TestCase;
use Zend\EventManager\EventManager;
use Zend\EventManager\SharedEventManager;
use Zend\Mvc\Controller\ControllerManager;
use Zend\Mvc\Service;
use Zend\ServiceManager\ServiceLocatorInterface;
use Zend\ServiceManager\ServiceManager;
use Zend\ServiceManager\Config;

class ResourceControllerFactoryTest extends TestCase
{
    public function setUp()
    {
        $this->services    = $services    = new ServiceManager();
        $this->controllers = $controllers = new ControllerManager($this->services);
        $this->factory     = $factory     = new ResourceControllerFactory();

        $controllers->addAbstractFactory($factory);
        $controllers->setServiceLocator($services);

        $services->setService(ServiceLocatorInterface::class, $services);
        $services->setService('config', $this->getConfig());
        $services->setService('ControllerManager', $controllers);
        $services->setFactory('ControllerPluginManager', Service\ControllerPluginManagerFactory::class);
        $services->setInvokableClass('EventManager', EventManager::class);
        $services->setInvokableClass('SharedEventManager', SharedEventManager::class);
        $services->setShared('EventManager', false);
    }

    public function getConfig()
    {
        return [
            'phlyrestfully' => [
                'resources' => [
                    'ApiController' => [
                        'listener'   => TestAsset\Listener::class,
                        'route_name' => 'api',
                    ],
                ],
            ],
        ];
    }

    /**
     * @group fail
     */
    public function testWillInstantiateListenerIfServiceNotFoundButClassExists()
    {
        $this->assertTrue($this->controllers->has('ApiController'));
        $controller = $this->controllers->get('ApiController');
        $this->assertInstanceOf(ResourceController::class, $controller);
    }

    public function testWillInstantiateAlternateResourceControllerWhenSpecified()
    {
        $config = $this->services->get('Config');
        $config['phlyrestfully']['resources']['ApiController']['controller_class'] = TestAsset\CustomController::class;
        $this->services->setAllowOverride(true);
        $this->services->setService('Config', $config);
        $controller = $this->controllers->get('ApiController');
        $this->assertInstanceOf(TestAsset\CustomController::class, $controller);
    }
}
