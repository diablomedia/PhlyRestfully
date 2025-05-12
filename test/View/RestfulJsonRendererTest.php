<?php declare(strict_types=1);
/**
 * @link      https://github.com/weierophinney/PhlyRestfully for the canonical source repository
 * @copyright Copyright (c) 2013 Matthew Weier O'Phinney
 * @license   http://opensource.org/licenses/BSD-2-Clause BSD-2-Clause
 * @package   PhlyRestfully
 */

namespace PhlyRestfullyTest\View;

use PhlyRestfully\ApiProblem;
use PhlyRestfully\HalCollection;
use PhlyRestfully\HalResource;
use PhlyRestfully\Link;
use PhlyRestfully\Plugin\HalLinks;
use PhlyRestfully\View\RestfulJsonModel;
use PhlyRestfully\View\RestfulJsonRenderer;
use PhlyRestfullyTest\TestAsset;
use PHPUnit\Framework\TestCase as TestCase;
use ReflectionObject;
use Laminas\Hydrator\HydratorPluginManager;
use Laminas\Router\Http\Segment;
use Laminas\Router\Http\TreeRouteStack;
use Laminas\Paginator\Adapter\ArrayAdapter;
use Laminas\Paginator\Paginator;
use Laminas\ServiceManager\ServiceManager;
use Laminas\Hydrator;
use Laminas\View\HelperPluginManager;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use stdClass;

/**
 * @subpackage UnitTest
 */
class RestfulJsonRendererTest extends TestCase
{
    private RestfulJsonRenderer $renderer;
    private ServiceManager $serviceManager;
    private HelperPluginManager $helpers;
    private TreeRouteStack $router;
    private Segment $resourceRoute;

    public function setUp(): void
    {
        $this->renderer = new RestfulJsonRenderer();
        $this->serviceManager = new ServiceManager();
    }

    public function assertIsHalResource($resource): void
    {
        $this->assertInstanceOf('stdClass', $resource, 'Invalid HAL resource; not an object');
        $this->assertObjectHasProperty('_links', $resource, 'Invalid HAL resource; does not contain links');
        $links = $resource->_links;
        $this->assertInstanceOf('stdClass', $links, 'Invalid HAL resource; links are not an object');
    }

    public function assertHalResourceHasRelationalLink($relation, $resource): void
    {
        $this->assertIsHalResource($resource);
        $links = $resource->_links;
        $this->assertObjectHasProperty(
            $relation,
            $links,
            sprintf('HAL links do not contain relation "%s"', $relation)
        );
        $link = $links->{$relation};
        $this->assertInstanceOf('stdClass', $link, sprintf('Relational links for "%s" are malformed', $relation));
    }

    public function assertRelationalLinkContains($match, $relation, $resource): void
    {
        $this->assertHalResourceHasRelationalLink($relation, $resource);
        $link = $resource->_links->{$relation};
        $this->assertObjectHasProperty(
            'href',
            $link,
            sprintf('%s relational link does not have an href attribute; received %s', $relation, var_export($link, true))
        );
        $href = $link->href;
        $this->assertStringContainsString($match, $href);
    }

    public function assertRelationalLinkEquals($match, $relation, $resource): void
    {
        $this->assertHalResourceHasRelationalLink($relation, $resource);
        $link = $resource->_links->{$relation};
        $this->assertObjectHasProperty(
            'href',
            $link,
            sprintf('%s relational link does not have an href attribute; received %s', $relation, var_export($link, true))
        );
        $href = $link->href;
        $this->assertEquals($match, $href);
    }

    public function nonRestfulJsonModels()
    {
        return [
            'view-model' => [new ViewModel(['foo' => 'bar'])],
            'json-view-model' => [new JsonModel(['foo' => 'bar'])],
        ];
    }

    /**
     * @dataProvider nonRestfulJsonModels
     */
    public function testPassesNonRestfulJsonModelToParentToRender($model): void
    {
        $payload = $this->renderer->render($model);
        $expected = json_encode(['foo' => 'bar']);
        $this->assertEquals($expected, $payload);
    }

    public function testRendersApiProblemCorrectly(): void
    {
        $apiProblem = new ApiProblem(401, 'login error', 'http://status.dev/errors.md', 'Unauthorized');
        $model      = new RestfulJsonModel();
        $model->setPayload($apiProblem);
        $test = $this->renderer->render($model);
        $expected = [
            'httpStatus'  => 401,
            'describedBy' => 'http://status.dev/errors.md',
            'title'       => 'Unauthorized',
            'detail'      => 'login error',
        ];
        $this->assertEquals($expected, json_decode($test, true));
    }

    public function setUpHelpers(): void
    {
        // need to setup routes
        // need to get a url and serverurl helper that have appropriate injections
        $this->router = $router = new TreeRouteStack();
        $this->resourceRoute = new Segment('/resource[/[:id]]');
        $this->router->addRoute('resource', $this->resourceRoute);

        $hydratorPluginManager = new HydratorPluginManager($this->serviceManager);

        $this->helpers = $helpers  = new HelperPluginManager($this->serviceManager);
        $serverUrl = $helpers->get('ServerUrl');
        $url       = $helpers->get('url');
        $url->setRouter($router);
        $serverUrl->setScheme('http');
        $serverUrl->setHost('localhost.localdomain');
        $halLinks  = new HalLinks($hydratorPluginManager);
        $halLinks->setServerUrlHelper($serverUrl);
        $halLinks->setUrlHelper($url);
        $helpers->setService('HalLinks', $halLinks);

        $this->renderer->setHelperPluginManager($helpers);
    }

    public function testRendersHalResourceWithAssociatedLinks(): void
    {
        $this->setUpHelpers();

        $item = new HalResource([
            'foo' => 'bar',
            'id'  => 'identifier',
        ], 'identifier');
        $links = $item->getLinks();
        $self  = new Link('self');
        $self->setRoute('resource')->setRouteParams(['id' => 'identifier']);
        $links->add($self);

        $model = new RestfulJsonModel(['payload' => $item]);
        $test  = $this->renderer->render($model);
        $test  = json_decode($test);

        $this->assertRelationalLinkEquals('http://localhost.localdomain/resource/identifier', 'self', $test);
        $this->assertObjectHasProperty('foo', $test);
        $this->assertEquals('bar', $test->foo);
    }

    public function testCanRenderStdclassHalResource(): void
    {
        $this->setUpHelpers();

        $item = (object) [
            'foo' => 'bar',
            'id'  => 'identifier',
        ];

        $item  = new HalResource($item, 'identifier', 'resource');
        $links = $item->getLinks();
        $self  = new Link('self');
        $self->setRoute('resource')->setRouteParams(['id' => 'identifier']);
        $links->add($self);

        $model = new RestfulJsonModel(['payload' => $item]);
        $test  = $this->renderer->render($model);
        $test  = json_decode($test);

        $this->assertRelationalLinkEquals('http://localhost.localdomain/resource/identifier', 'self', $test);
        $this->assertObjectHasProperty('foo', $test);
        $this->assertEquals('bar', $test->foo);
    }

    public function testCanSerializeHydratableHalResource(): void
    {
        $this->setUpHelpers();
        $this->helpers->get('HalLinks')->addHydrator(
            TestAsset\ArraySerializable::class,
            new Hydrator\ArraySerializableHydrator()
        );

        $item  = new TestAsset\ArraySerializable();
        $item  = new HalResource(new TestAsset\ArraySerializable(), 'identifier', 'resource');
        $links = $item->getLinks();
        $self  = new Link('self');
        $self->setRoute('resource')->setRouteParams(['id' => 'identifier']);
        $links->add($self);

        $model = new RestfulJsonModel(['payload' => $item]);
        $test  = $this->renderer->render($model);
        $test  = json_decode($test);

        $this->assertRelationalLinkEquals('http://localhost.localdomain/resource/identifier', 'self', $test);
        $this->assertObjectHasProperty('foo', $test);
        $this->assertEquals('bar', $test->foo);
    }

    public function testUsesDefaultHydratorIfAvailable(): void
    {
        $this->setUpHelpers();
        $this->helpers->get('HalLinks')->setDefaultHydrator(
            new Hydrator\ArraySerializableHydrator()
        );

        $item  = new TestAsset\ArraySerializable();
        $item  = new HalResource(new TestAsset\ArraySerializable(), 'identifier', 'resource');
        $links = $item->getLinks();
        $self  = new Link('self');
        $self->setRoute('resource')->setRouteParams(['id' => 'identifier']);
        $links->add($self);

        $model = new RestfulJsonModel(['payload' => $item]);
        $test  = $this->renderer->render($model);
        $test  = json_decode($test);

        $this->assertRelationalLinkEquals('http://localhost.localdomain/resource/identifier', 'self', $test);
        $this->assertObjectHasProperty('foo', $test);
        $this->assertEquals('bar', $test->foo);
    }

    public function testCanRenderNonPaginatedHalCollection(): void
    {
        $this->setUpHelpers();

        $prototype = ['foo' => 'bar'];
        $items = [];
        foreach (range(1, 100) as $id) {
            $item       = $prototype;
            $item['id'] = $id;
            $items[]    = $item;
        }

        $collection = new HalCollection($items);
        $collection->setCollectionRoute('resource');
        $collection->setResourceRoute('resource');
        $links = $collection->getLinks();
        $self  = new Link('self');
        $self->setRoute('resource');
        $links->add($self);

        $model      = new RestfulJsonModel(['payload' => $collection]);
        $test       = $this->renderer->render($model);
        $test       = json_decode($test);

        $this->assertRelationalLinkEquals('http://localhost.localdomain/resource', 'self', $test);

        $this->assertObjectHasProperty('_embedded', $test);
        $this->assertInstanceof('stdClass', $test->_embedded);
        $this->assertObjectHasProperty('items', $test->_embedded);
        $this->assertIsArray($test->_embedded->items);
        $this->assertCount(100, $test->_embedded->items);

        foreach ($test->_embedded->items as $key => $item) {
            $id = $key + 1;

            $this->assertRelationalLinkEquals('http://localhost.localdomain/resource/' . $id, 'self', $item);
            $this->assertObjectHasProperty('id', $item, var_export($item, true));
            $this->assertEquals($id, $item->id);
            $this->assertObjectHasProperty('foo', $item);
            $this->assertEquals('bar', $item->foo);
        }
    }

    public function testCanRenderPaginatedHalCollection(): void
    {
        $this->setUpHelpers();

        $prototype = ['foo' => 'bar'];
        $items = [];
        foreach (range(1, 100) as $id) {
            $item       = $prototype;
            $item['id'] = $id;
            $items[]    = $item;
        }
        $adapter   = new ArrayAdapter($items);
        $paginator = new Paginator($adapter);

        $collection = new HalCollection($paginator);
        $collection->setPageSize(5);
        $collection->setPage(3);
        $collection->setCollectionRoute('resource');
        $collection->setResourceRoute('resource');
        $links = $collection->getLinks();
        $self  = new Link('self');
        $self->setRoute('resource');
        $links->add($self);

        $model      = new RestfulJsonModel(['payload' => $collection]);
        $test       = $this->renderer->render($model);
        $test       = json_decode($test);

        $this->assertInstanceof('stdClass', $test, var_export($test, true));
        $this->assertRelationalLinkEquals('http://localhost.localdomain/resource?page=3', 'self', $test);
        $this->assertRelationalLinkEquals('http://localhost.localdomain/resource', 'first', $test);
        $this->assertRelationalLinkEquals('http://localhost.localdomain/resource?page=20', 'last', $test);
        $this->assertRelationalLinkEquals('http://localhost.localdomain/resource?page=2', 'prev', $test);
        $this->assertRelationalLinkEquals('http://localhost.localdomain/resource?page=4', 'next', $test);

        $this->assertObjectHasProperty('_embedded', $test);
        $this->assertInstanceof('stdClass', $test->_embedded);
        $this->assertObjectHasProperty('items', $test->_embedded);
        $this->assertIsArray($test->_embedded->items);
        $this->assertCount(5, $test->_embedded->items);

        foreach ($test->_embedded->items as $key => $item) {
            $id = $key + 11;

            $this->assertRelationalLinkEquals('http://localhost.localdomain/resource/' . $id, 'self', $item);
            $this->assertObjectHasProperty('id', $item, var_export($item, true));
            $this->assertEquals($id, $item->id);
            $this->assertObjectHasProperty('foo', $item);
            $this->assertEquals('bar', $item->foo);
        }
    }

    public function invalidPages()
    {
        return [
            '-1'   => [-1],
            '1000' => [1000],
        ];
    }

    /**
     * @dataProvider invalidPages
     */
    public function testRenderingPaginatedCollectionCanReturnApiProblemIfPageIsTooHighOrTooLow($page): void
    {
        $this->setUpHelpers();

        $prototype = ['foo' => 'bar'];
        $items = [];
        foreach (range(1, 100) as $id) {
            $item       = $prototype;
            $item['id'] = $id;
            $items[]    = $item;
        }
        $adapter   = new ArrayAdapter($items);
        $paginator = new Paginator($adapter);

        $collection = new HalCollection($paginator, 'resource');
        $collection->setPageSize(5);

        // Using reflection object so we can force a negative page number if desired
        $r = new ReflectionObject($collection);
        $p = $r->getProperty('page');
        $p->setAccessible(true);
        $p->setValue($collection, $page);

        $model      = new RestfulJsonModel(['payload' => $collection]);
        $test       = $this->renderer->render($model);
        $test       = json_decode($test);

        $this->assertObjectHasProperty('httpStatus', $test, var_export($test, true));
        $this->assertEquals(409, $test->httpStatus);
        $this->assertObjectHasProperty('detail', $test);
        $this->assertEquals('Invalid page provided', $test->detail);

        $this->assertTrue($this->renderer->isApiProblem());
        $problem = $this->renderer->getApiProblem();
        $this->assertInstanceof(ApiProblem::class, $problem);
        $problem = $problem->toArray();
        $this->assertEquals(409, $problem['httpStatus']);
    }

    public function testCanHintToApiProblemToRenderStackTrace(): void
    {
        $exception  = new \Exception('exception message', 500);
        $apiProblem = new ApiProblem(500, $exception);
        $model      = new RestfulJsonModel();
        $model->setPayload($apiProblem);
        $this->renderer->setDisplayExceptions(true);
        $test = $this->renderer->render($model);
        $test = json_decode($test, true);
        $this->assertStringContainsString($exception->getMessage() . "\n" . $exception->getTraceAsString(), $test['detail']);
    }

    public function testRendersAttributesAsPartOfNonPaginatedHalCollection(): void
    {
        $this->setUpHelpers();

        $attributes = [
            'count' => 100,
            'type'  => 'foo',
        ];

        $prototype = ['foo' => 'bar'];
        $items = [];
        foreach (range(1, 100) as $id) {
            $item       = $prototype;
            $item['id'] = $id;
            $items[]    = $item;
        }

        $collection = new HalCollection($items, 'resource');
        $collection->setAttributes($attributes);

        $model      = new RestfulJsonModel(['payload' => $collection]);
        $test       = $this->renderer->render($model);
        $test       = json_decode($test);

        $this->assertInstanceof('stdClass', $test, var_export($test, true));
        $this->assertObjectHasProperty('count', $test, var_export($test, true));
        $this->assertEquals(100, $test->count);
        $this->assertObjectHasProperty('type', $test);
        $this->assertEquals('foo', $test->type);
    }

    public function testRendersAttributeAsPartOfPaginatedCollectionResource(): void
    {
        $this->setUpHelpers();

        $attributes = [
            'count' => 100,
            'type'  => 'foo',
        ];

        $prototype = ['foo' => 'bar'];
        $items = [];
        foreach (range(1, 100) as $id) {
            $item       = $prototype;
            $item['id'] = $id;
            $items[]    = $item;
        }
        $adapter   = new ArrayAdapter($items);
        $paginator = new Paginator($adapter);

        $collection = new HalCollection($paginator);
        $collection->setPageSize(5);
        $collection->setPage(3);
        $collection->setAttributes($attributes);
        $collection->setCollectionRoute('resource');
        $collection->setResourceRoute('resource');
        $links = $collection->getLinks();
        $self  = new Link('self');
        $self->setRoute('resource');
        $links->add($self);

        $model      = new RestfulJsonModel(['payload' => $collection]);
        $test       = $this->renderer->render($model);
        $test       = json_decode($test);

        $this->assertInstanceof('stdClass', $test, var_export($test, true));
        $this->assertObjectHasProperty('count', $test, var_export($test, true));
        $this->assertEquals(100, $test->count);
        $this->assertObjectHasProperty('type', $test);
        $this->assertEquals('foo', $test->type);
    }

    public function testCanRenderNestedHalResourcesAsEmbeddedResources(): void
    {
        $this->setUpHelpers();
        $this->router->addRoute('user', new Segment('/user[/:id]'));

        $child = new HalResource([
            'id'     => 'matthew',
            'name'   => 'matthew',
            'github' => 'weierophinney',
        ], 'matthew', 'user');
        $link = new Link('self');
        $link->setRoute('user')->setRouteParams(['id' => 'matthew']);
        $child->getLinks()->add($link);

        $item = new HalResource([
            'foo'  => 'bar',
            'id'   => 'identifier',
            'user' => $child,
        ], 'identifier', 'resource');
        $link = new Link('self');
        $link->setRoute('resource')->setRouteParams(['id' => 'identifier']);
        $item->getLinks()->add($link);

        $model = new RestfulJsonModel(['payload' => $item]);
        $test  = $this->renderer->render($model);
        $test  = json_decode($test);

        $this->assertObjectNotHasProperty('user', $test);
        $this->assertObjectHasProperty('_embedded', $test);
        $embedded = $test->_embedded;
        $this->assertObjectHasProperty('user', $embedded);
        $user = $embedded->user;
        $this->assertRelationalLinkContains('/user/matthew', 'self', $user);
        $user = (array) $user;
        foreach ($child->resource as $key => $value) {
            $this->assertArrayHasKey($key, $user);
            $this->assertEquals($value, $user[$key]);
        }
    }

    public function testRendersEmbeddedResourcesOfIndividualNonPaginatedCollectionResources(): void
    {
        $this->setUpHelpers();
        $this->router->addRoute('user', new Segment('/user[/:id]'));

        $child = new HalResource([
            'id'     => 'matthew',
            'name'   => 'matthew',
            'github' => 'weierophinney',
        ], 'matthew', 'user');
        $link = new Link('self');
        $link->setRoute('user')->setRouteParams(['id' => 'matthew']);
        $child->getLinks()->add($link);

        $prototype = ['foo' => 'bar', 'user' => $child];
        $items = [];
        foreach (range(1, 3) as $id) {
            $item       = $prototype;
            $item['id'] = $id;
            $items[]    = $item;
        }

        $collection = new HalCollection($items);
        $collection->setCollectionRoute('resource');
        $collection->setResourceRoute('resource');
        $links = $collection->getLinks();
        $self  = new Link('self');
        $self->setRoute('resource');
        $links->add($self);

        $model = new RestfulJsonModel(['payload' => $collection]);
        $test  = $this->renderer->render($model);
        $test  = json_decode($test);

        $this->assertInstanceof('stdClass', $test, var_export($test, true));
        $collection = $test->_embedded->items;
        foreach ($collection as $item) {
            $this->assertObjectHasProperty('_embedded', $item);
            $embedded = $item->_embedded;
            $this->assertObjectHasProperty('user', $embedded);
            $user = $embedded->user;
            $this->assertRelationalLinkContains('/user/matthew', 'self', $user);
            $user = (array) $user;
            foreach ($child->resource as $key => $value) {
                $this->assertArrayHasKey($key, $user);
                $this->assertEquals($value, $user[$key]);
            }
        }
    }

    public function testRendersEmbeddedResourcesOfIndividualPaginatedCollectionResources(): void
    {
        $this->setUpHelpers();
        $this->router->addRoute('user', new Segment('/user[/:id]'));

        $child = new HalResource([
            'id'     => 'matthew',
            'name'   => 'matthew',
            'github' => 'weierophinney',
        ], 'matthew', 'user');
        $link = new Link('self');
        $link->setRoute('user')->setRouteParams(['id' => 'matthew']);
        $child->getLinks()->add($link);

        $prototype = ['foo' => 'bar', 'user' => $child];
        $items = [];
        foreach (range(1, 3) as $id) {
            $item       = $prototype;
            $item['id'] = $id;
            $items[]    = $item;
        }
        $adapter   = new ArrayAdapter($items);
        $paginator = new Paginator($adapter);

        $collection = new HalCollection($paginator);
        $collection->setPageSize(5);
        $collection->setPage(1);
        $collection->setCollectionRoute('resource');
        $collection->setResourceRoute('resource');
        $links = $collection->getLinks();
        $self  = new Link('self');
        $self->setRoute('resource');
        $links->add($self);

        $model      = new RestfulJsonModel(['payload' => $collection]);
        $test       = $this->renderer->render($model);
        $test       = json_decode($test);

        $this->assertInstanceof('stdClass', $test, var_export($test, true));
        $collection = $test->_embedded->items;
        foreach ($collection as $item) {
            $this->assertObjectHasProperty('_embedded', $item, var_export($item, true));
            $embedded = $item->_embedded;
            $this->assertObjectHasProperty('user', $embedded);
            $user = $embedded->user;
            $this->assertRelationalLinkContains('/user/matthew', 'self', $user);
            $user = (array) $user;
            foreach ($child->resource as $key => $value) {
                $this->assertArrayHasKey($key, $user);
                $this->assertEquals($value, $user[$key]);
            }
        }
    }

    public function testAllowsSpecifyingAlternateCallbackForReturningResourceId(): void
    {
        $this->setUpHelpers();

        $this->helpers->get('HalLinks')->getEventManager()->attach('getIdFromResource', function ($e) {
            $resource = $e->getParam('resource');

            if (!is_array($resource)) {
                return false;
            }

            if (array_key_exists('name', $resource)) {
                return $resource['name'];
            }

            return false;
        }, 10);


        $prototype = ['foo' => 'bar'];
        $items = [];
        foreach (range(1, 100) as $id) {
            $item         = $prototype;
            $item['name'] = $id;
            $items[]      = $item;
        }

        $collection = new HalCollection($items);
        $collection->setCollectionRoute('resource');
        $collection->setResourceRoute('resource');
        $links = $collection->getLinks();
        $self  = new Link('self');
        $self->setRoute('resource');
        $links->add($self);

        $model      = new RestfulJsonModel(['payload' => $collection]);
        $test       = $this->renderer->render($model);
        $test       = json_decode($test);

        $this->assertInstanceof(stdClass::class, $test, var_export($test, true));
        $this->assertRelationalLinkEquals('http://localhost.localdomain/resource', 'self', $test);

        $this->assertObjectHasProperty('_embedded', $test);
        $this->assertInstanceof(stdClass::class, $test->_embedded);
        $this->assertObjectHasProperty('items', $test->_embedded);
        $this->assertIsArray($test->_embedded->items);
        $this->assertCount(100, $test->_embedded->items);

        foreach ($test->_embedded->items as $key => $item) {
            $id = $key + 1;

            $this->assertRelationalLinkEquals('http://localhost.localdomain/resource/' . $id, 'self', $item);
            $this->assertObjectHasProperty('name', $item, var_export($item, true));
            $this->assertEquals($id, $item->name);
            $this->assertObjectHasProperty('foo', $item);
            $this->assertEquals('bar', $item->foo);
        }
    }
}
