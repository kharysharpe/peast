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
 * A node that represents a tagget template expression.
 * For example: fn`template`
 * 
 * @author Marco Marchiò <marco.mm89@gmail.com>
 */
class TaggedTemplateExpression extends Node implements Expression
{
    /**
     * Properties containing child nodes
     * 
     * @var array 
     */
    protected $childNodesProps = array("tag", "quasi");
    
    /**
     * Tag expression
     * 
     * @var Expression 
     */
    protected $tag;
    
    /**
     * Template
     * 
     * @var TemplateLiteral 
     */
    protected $quasi;
    
    /**
     * Returns the tag expression
     * 
     * @return Expression
     */
    public function getTag()
    {
        return $this->tag;
    }
    
    /**
     * Sets the tag expression
     * 
     * @param Expression $tag Tag expression
     * 
     * @return $this
     */
    public function setTag(Expression $tag)
    {
        $this->tag = $tag;
        return $this;
    }
    
    /**
     * Returns the template
     * 
     * @return TemplateLiteral
     */
    public function getQuasi()
    {
        return $this->quasi;
    }
    
    /**
     * Sets the the template
     * 
     * @param TemplateLiteral $quasi Template
     * 
     * @return $this
     */
    public function setQuasi(TemplateLiteral $quasi)
    {
        $this->quasi = $quasi;
        return $this;
    }
}