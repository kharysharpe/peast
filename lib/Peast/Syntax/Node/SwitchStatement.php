<?php
namespace Peast\Syntax\Node;

class SwitchStatement extends Statement
{
    protected $discriminant;
    
    protected $cases = array();
    
    public function getDiscriminant()
    {
        return $this->discriminant;
    }
    
    public function setDiscriminant(Expression $discriminant)
    {
        $this->discriminant = $discriminant;
        return $this;
    }
    
    public function getCases()
    {
        return $this->cases;
    }
    
    public function setCases($cases)
    {
        $this->assertArrayOf($body, "SwitchCase");
        $this->cases = $cases;
        return $this;
    }
    
    public function getSource()
    {
        return "switch (" . $this->getDiscriminant()->getSource() . ") {" .
               $this->nodeListToSource($this->getBody()) .
               "}";
    }
}