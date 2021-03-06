<?php
/**
 * This file is part of the Peast package
 *
 * (c) Marco Marchiò <marco.mm89@gmail.com>
 *
 * For the full copyright and license information refer to the LICENSE file
 * distributed with this source code
 */
namespace Peast\Syntax\Node;

/**
 * Abstract class that export and import specifiers nodes must extend.
 * 
 * @author Marco Marchiò <marco.mm89@gmail.com>
 * 
 * @abstract
 */
abstract class ModuleSpecifier extends Node
{
    /**
     * Properties containing child nodes
     * 
     * @var array 
     */
    protected $childNodesProps = array("local");
    
    /**
     * Local identifier
     * 
     * @var Identifier 
     */
    protected $local;
    
    /**
     * Returns the local identifier
     * 
     * @return Identifier
     */
    public function getLocal()
    {
        return $this->local;
    }
    
    /**
     * Sets the local identifier
     * 
     * @param Identifier $local Local identifier
     * 
     * @return $this
     */
    public function setLocal(Identifier $local)
    {
        $this->local = $local;
        return $this;
    }
}