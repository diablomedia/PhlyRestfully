<?php declare(strict_types=1);
/**
 * @link      https://github.com/weierophinney/PhlyRestfully for the canonical source repository
 * @copyright Copyright (c) 2013 Matthew Weier O'Phinney
 * @license   http://opensource.org/licenses/BSD-2-Clause BSD-2-Clause
 * @package   PhlyRestfully
 */

namespace PhlyRestfullyTest;

use PhlyRestfully\Link;
use PhlyRestfully\LinkCollection;
use PHPUnit\Framework\TestCase as TestCase;

class LinkCollectionTest extends TestCase
{
    private LinkCollection $links;

    public function setUp(): void
    {
        $this->links = new LinkCollection();
    }

    public function testCanAddDiscreteLinkRelations(): void
    {
        $describedby = new Link('describedby');
        $self = new Link('self');
        $this->links->add($describedby);
        $this->links->add($self);

        $this->assertTrue($this->links->has('describedby'));
        $this->assertSame($describedby, $this->links->get('describedby'));
        $this->assertTrue($this->links->has('self'));
        $this->assertSame($self, $this->links->get('self'));
    }

    public function testCanAddDuplicateLinkRelations(): void
    {
        $order1 = new Link('order');
        $order2 = new Link('order');

        $this->links->add($order1)
                    ->add($order2);

        $this->assertTrue($this->links->has('order'));
        $orders = $this->links->get('order');
        $this->assertIsArray($orders);
        $this->assertContains($order1, $orders);
        $this->assertContains($order2, $orders);
    }

    public function testCanRemoveLinkRelations(): void
    {
        $describedby = new Link('describedby');
        $this->links->add($describedby);
        $this->assertTrue($this->links->has('describedby'));
        $this->links->remove('describedby');
        $this->assertFalse($this->links->has('describedby'));
    }

    public function testCanOverwriteLinkRelations(): void
    {
        $order1 = new Link('order');
        $order2 = new Link('order');

        $this->links->add($order1)
                    ->add($order2, true);

        $this->assertTrue($this->links->has('order'));
        $orders = $this->links->get('order');
        $this->assertSame($order2, $orders);
    }

    public function testCanIterateLinks(): void
    {
        $describedby = new Link('describedby');
        $self = new Link('self');
        $this->links->add($describedby);
        $this->links->add($self);

        $this->assertEquals(2, $this->links->count());
        $i = 0;
        foreach ($this->links as $link) {
            $this->assertInstanceOf(Link::class, $link);
            $i += 1;
        }
        $this->assertEquals(2, $i);
    }
}
