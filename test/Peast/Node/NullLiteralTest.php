<?php
namespace test\Peast\Node;

use \Peast\Syntax\Node;

class NullLiteralTest extends \test\Peast\TestBase
{
    public function testValue()
    {
        $node = new Node\NullLiteral;
        
        $this->assertEquals(null, $node->getValue());
        $this->assertEquals("null", $node->getRaw());
        
        $node->setValue(123);
        $this->assertEquals(null, $node->getValue());
        $this->assertEquals("null", $node->getRaw());
    }
}