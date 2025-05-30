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
use PhlyRestfully\View\RestfulJsonModel;
use PHPUnit\Framework\TestCase as TestCase;
use stdClass;

/**
 * @subpackage UnitTest
 */
class RestfulJsonModelTest extends TestCase
{
    private RestfulJsonModel $model;

    public function setUp(): void
    {
        $this->model = new RestfulJsonModel;
    }

    public function testPayloadIsNullByDefault(): void
    {
        $this->assertNull($this->model->getPayload());
    }

    public function testPayloadIsMutable(): void
    {
        $this->model->setPayload('foo');
        $this->assertEquals('foo', $this->model->getPayload());
    }

    public function invalidPayloads()
    {
        return [
            'null'       => [null],
            'true'       => [true],
            'false'      => [false],
            'zero-int'   => [0],
            'int'        => [1],
            'zero-float' => [0.0],
            'float'      => [1.1],
            'string'     => ['string'],
            'array'      => [[]],
            'stdclass'   => [new stdClass],
        ];
    }

    public function invalidApiProblemPayloads()
    {
        $payloads = $this->invalidPayloads();
        $payloads['hal-collection'] = [new HalCollection([], 'item/route')];
        $payloads['hal-item'] = [new HalResource([], 'id', 'route')];
        return $payloads;
    }

    /**
     * @dataProvider invalidApiProblemPayloads
     */
    public function testIsApiProblemReturnsFalseForInvalidValues($payload): void
    {
        $this->model->setPayload($payload);
        $this->assertFalse($this->model->isApiProblem());
    }

    public function testIsApiProblemReturnsTrueForApiProblemPayload(): void
    {
        $problem = new ApiProblem(401, 'Unauthorized');
        $this->model->setPayload($problem);
        $this->assertTrue($this->model->isApiProblem());
    }

    public function invalidHalCollectionPayloads()
    {
        $payloads = $this->invalidPayloads();
        $payloads['api-problem'] = [new ApiProblem(401, 'unauthorized')];
        $payloads['hal-item'] = [new HalResource([], 'id', 'route')];
        return $payloads;
    }

    /**
     * @dataProvider invalidHalCollectionPayloads
     */
    public function testIsHalCollectionReturnsFalseForInvalidValues($payload): void
    {
        $this->model->setPayload($payload);
        $this->assertFalse($this->model->isHalCollection());
    }

    public function testIsHalCollectionReturnsTrueForHalCollectionPayload(): void
    {
        $collection = new HalCollection([], 'item/route');
        $this->model->setPayload($collection);
        $this->assertTrue($this->model->isHalCollection());
    }

    public function invalidHalResourcePayloads()
    {
        $payloads = $this->invalidPayloads();
        $payloads['api-problem'] = [new ApiProblem(401, 'unauthorized')];
        $payloads['hal-collection'] = [new HalCollection([], 'item/route')];
        return $payloads;
    }

    /**
     * @dataProvider invalidHalResourcePayloads
     */
    public function testIsHalResourceReturnsFalseForInvalidValues($payload): void
    {
        $this->model->setPayload($payload);
        $this->assertFalse($this->model->isHalResource());
    }

    public function testIsHalResourceReturnsTrueForHalResourcePayload(): void
    {
        $item = new HalResource([], 'id', 'route');
        $this->model->setPayload($item);
        $this->assertTrue($this->model->isHalResource());
    }
}
