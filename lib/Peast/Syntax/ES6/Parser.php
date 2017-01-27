<?php
/**
 * This file is part of the Peast package
 *
 * (c) Marco Marchiò <marco.mm89@gmail.com>
 *
 * For the full copyright and license information refer to the LICENSE file
 * distributed with this source code
 */
namespace Peast\Syntax\ES6;

use Peast\Syntax\Token;
use \Peast\Syntax\Node;

/**
 * ES6 parser class
 * 
 * @author Marco Marchiò <marco.mm89@gmail.com>
 */
class Parser extends \Peast\Syntax\Parser
{
    /**
     * Assignment operators
     * 
     * @var array 
     */
    protected $assignmentOperators = array(
        "=", "+=", "-=", "*=", "/=", "%=", "<<=", ">>=", ">>>=", "&=", "^=",
        "|="
    );
    
    /**
     * Logical and binary operators
     * 
     * @var array 
     */
    protected $logicalBinaryOperators = array(
        "||" => 0,
        "&&" => 1,
        "|" => 2,
        "^" => 3,
        "&" => 4,
        "===" => 5, "!==" => 5, "==" => 5, "!=" => 5,
        "<=" => 6, ">=" => 6, "<" => 6, ">" => 6,
        "instanceof" => 6, "in" => 6,
        ">>>" => 7, "<<" => 7, ">>" => 7,
        "+" => 8, "-" => 8,
        "*" => 9, "/" => 9, "%" => 9
    );
    
    /**
     * Unary operators
     * 
     * @var array 
     */
    protected $unaryOperators = array(
        "delete", "void", "typeof", "++", "--", "+", "-", "~", "!"
    );
    
    /**
     * Postfix operators
     * 
     * @var array 
     */
    protected $postfixOperators = array("--", "++");
    
    /**
     * Initializes parser context
     */
    protected function initContext()
    {
        $this->context = (object) array(
            "allowReturn" => false
        );
    }
    
    /**
     * Parses the source
     * 
     * @return Node\Program
     */
    public function parse()
    {
        $this->initContext();
        
        $type = isset($this->options["sourceType"]) ?
                $this->options["sourceType"] :
                \Peast\Peast::SOURCE_TYPE_SCRIPT;
        
        if ($type === \Peast\Peast::SOURCE_TYPE_MODULE) {
            $this->scanner->setStrictMode(true);
            $body = $this->parseModuleItemList();
        } else {
            $body = $this->parseStatementList(false, true);
        }
        
        $node = $this->createNode(
            "Program", $body ? $body : $this->scanner->getPosition()
        );
        $node->setSourceType($type);
        if ($body) {
            $node->setBody($body);
        }
        $program = $this->completeNode($node);
        if ($this->scanner->getToken()) {
            return $this->error();
        }
        return $program;
    }
    
    /**
     * Converts an expression node to a pattern node
     * 
     * @param Node\Node $node The node to convert
     * 
     * @return Node\Node
     */
    protected function expressionToPattern($node)
    {
        $retNode = $node;
        if ($node instanceof Node\ArrayExpression) {
            
            $loc = $node->getLocation();
            $elems = array();
            foreach ($node->getElements() as $elem) {
                $elems[] = $this->expressionToPattern($elem);
            }
                
            $retNode = $this->createNode("ArrayPattern", $loc->getStart());
            $retNode->setElements($elems);
            $this->completeNode($retNode, $loc->getEnd());
            
        } elseif ($node instanceof Node\ObjectExpression) {
            
            $loc = $node->getLocation();
            $props = array();
            foreach ($node->getProperties() as $prop) {
                $props[] = $this->expressionToPattern($prop);
            }
                
            $retNode = $this->createNode("ObjectPattern", $loc->getStart());
            $retNode->setProperties($props);
            $this->completeNode($retNode, $loc->getEnd());
            
        } elseif ($node instanceof Node\Property) {
            
            $loc = $node->getLocation();
            $retNode = $this->createNode(
                "AssignmentProperty", $loc->getStart()
            );
            $retNode->setValue($node->getValue());
            $retNode->setKey($node->getKey());
            $retNode->setMethod($node->getMethod());
            $retNode->setShorthand($node->getShorthand());
            $retNode->setComputed($node->getComputed());
            $this->completeNode($retNode, $loc->getEnd());
            
        } elseif ($node instanceof Node\SpreadElement) {
            
            $loc = $node->getLocation();
            $retNode = $this->createNode("RestElement", $loc->getStart());
            $retNode->setArgument(
                $this->expressionToPattern($node->getArgument())
            );
            $this->completeNode($retNode, $loc->getEnd());
            
        } elseif ($node instanceof Node\AssignmentExpression) {
            
            $loc = $node->getLocation();
            $retNode = $this->createNode("AssignmentPattern", $loc->getStart());
            $retNode->setLeft($this->expressionToPattern($node->getLeft()));
            $retNode->setRight($node->getRight());
            $this->completeNode($retNode, $loc->getEnd());
            
        }
        return $retNode;
    }
    
    /**
     * Parses a statement list
     * 
     * @param bool $yield                   Yield mode
     * @param bool $parseDirectivePrologues True to parse directive prologues
     * 
     * @return Node\Node[]|null
     */
    protected function parseStatementList(
        $yield = false, $parseDirectivePrologues = false
    ) {
        $items = array();
        
        //Get directive prologues and check if strict mode is present
        $strictModeChanged = false;
        if ($parseDirectivePrologues) {
            if ($directives = $this->parseDirectivePrologues()) {
                $items = array_merge($items, $directives[0]);
                //If "use strict" is present store the current value and
                //restore it at the end of the function
                if (!$this->scanner->getStrictMode() &&
                    in_array("use strict", $directives[1])
                ) {
                    $strictModeChanged = true;
                    $this->scanner->setStrictMode(true);
                }
            }
        }
        
        while ($item = $this->parseStatementListItem($yield)) {
            $items[] = $item;
        }
        
        //Apply previous strict mode
        if ($strictModeChanged) {
            $this->scanner->setStrictMode(false);
        }
        
        return count($items) ? $items : null;
    }
    
    /**
     * Parses a statement list item
     * 
     * @param bool $yield  Yield mode
     * 
     * @return Node\Statement|Node\Declaration|null
     */
    protected function parseStatementListItem($yield = false)
    {
        if ($declaration = $this->parseDeclaration($yield)) {
            return $declaration;
        } elseif ($statement = $this->parseStatement($yield)) {
            return $statement;
        }
        return null;
    }
    
    /**
     * Parses a statement
     * 
     * @param bool $yield  Yield mode
     * 
     * @return Node\Statement|null
     */
    protected function parseStatement($yield = false)
    {
        if ($statement = $this->parseBlock($yield)) {
            return $statement;
        } elseif ($statement = $this->parseVariableStatement($yield)) {
            return $statement;
        } elseif ($statement = $this->parseEmptyStatement()) {
            return $statement;
        } elseif ($statement = $this->parseIfStatement($yield)) {
            return $statement;
        } elseif ($statement = $this->parseBreakableStatement($yield)) {
            return $statement;
        } elseif ($statement = $this->parseContinueStatement($yield)) {
            return $statement;
        } elseif ($statement = $this->parseBreakStatement($yield)) {
            return $statement;
        } elseif ($this->context->allowReturn && $statement = $this->parseReturnStatement($yield)) {
            return $statement;
        } elseif ($statement = $this->parseWithStatement($yield)) {
            return $statement;
        } elseif ($statement = $this->parseThrowStatement($yield)) {
            return $statement;
        } elseif ($statement = $this->parseTryStatement($yield)) {
            return $statement;
        } elseif ($statement = $this->parseDebuggerStatement()) {
            return $statement;
        } elseif ($statement = $this->parseLabelledStatement($yield)) {
            return $statement;
        } elseif ($statement = $this->parseExpressionStatement($yield)) {
            return $statement;
        }
        return null;
    }
    
    /**
     * Parses a declaration
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\Declaration|null
     */
    protected function parseDeclaration($yield = false)
    {
        if ($declaration = $this->parseFunctionOrGeneratorDeclaration($yield)) {
            return $declaration;
        } elseif ($declaration = $this->parseClassDeclaration($yield)) {
            return $declaration;
        } elseif ($declaration = $this->parseLexicalDeclaration(true, $yield)) {
            return $declaration;
        }
        return null;
    }
    
    /**
     * Parses a breakable statement
     * 
     * @param bool $yield  Yield mode
     * 
     * @return Node\Node|null
     */
    protected function parseBreakableStatement($yield = false)
    {
        if ($statement = $this->parseIterationStatement($yield)) {
            return $statement;
        } elseif ($statement = $this->parseSwitchStatement($yield)) {
            return $statement;
        }
        return null;
    }
    
    /**
     * Parses a block statement
     * 
     * @param bool $yield  Yield mode
     * 
     * @return Node\BlockStatement|null
     */
    protected function parseBlock($yield = false)
    {
        if ($token = $this->scanner->consume("{")) {
            
            $statements = $this->parseStatementList($yield);
            if ($this->scanner->consume("}")) {
                $node = $this->createNode("BlockStatement", $token);
                if ($statements) {
                    $node->setBody($statements);
                }
                return $this->completeNode($node);
            }
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a module item list
     * 
     * @return Node\Node[]|null
     */
    protected function parseModuleItemList()
    {
        $items = array();
        while ($item = $this->parseModuleItem()) {
            $items[] = $item;
        }
        return count($items) ? $items : null;
    }
    
    /**
     * Parses an empty statement
     * 
     * @return Node\EmptyStatement|null
     */
    protected function parseEmptyStatement()
    {
        if ($token = $this->scanner->consume(";")) {
            $node = $this->createNode("EmptyStatement", $token);
            return $this->completeNode($node);
        }
        return null;
    }
    
    /**
     * Parses a debugger statement
     * 
     * @return Node\DebuggerStatement|null
     */
    protected function parseDebuggerStatement()
    {
        if ($token = $this->scanner->consume("debugger")) {
            $node = $this->createNode("DebuggerStatement", $token);
            $this->assertEndOfStatement();
            return $this->completeNode($node);
        }
        return null;
    }
    
    /**
     * Parses an if statement
     * 
     * @param bool $yield  Yield mode
     * 
     * @return Node\IfStatement|null
     */
    protected function parseIfStatement($yield = false)
    {
        if ($token = $this->scanner->consume("if")) {
            
            if ($this->scanner->consume("(") &&
                ($test = $this->parseExpression(true, $yield)) &&
                $this->scanner->consume(")") &&
                $consequent = $this->parseStatement($yield)
            ) {
                
                $node = $this->createNode("IfStatement", $token);
                $node->setTest($test);
                $node->setConsequent($consequent);
                
                if ($this->scanner->consume("else")) {
                    if ($alternate = $this->parseStatement($yield)) {
                        $node->setAlternate($alternate);
                        return $this->completeNode($node);
                    }
                } else {
                    return $this->completeNode($node);
                }
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a try-catch statement
     * 
     * @param bool $yield  Yield mode
     * 
     * @return Node\TryStatement|null
     */
    protected function parseTryStatement($yield = false)
    {
        if ($token = $this->scanner->consume("try")) {
            
            if ($block = $this->parseBlock($yield)) {
                
                $node = $this->createNode("TryStatement", $token);
                $node->setBlock($block);

                if ($handler = $this->parseCatch($yield)) {
                    $node->setHandler($handler);
                }

                if ($finalizer = $this->parseFinally($yield)) {
                    $node->setFinalizer($finalizer);
                }

                if ($handler || $finalizer) {
                    return $this->completeNode($node);
                }
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses the catch block of a try-catch statement
     * 
     * @param bool $yield  Yield mode
     * 
     * @return Node\CatchClause|null
     */
    protected function parseCatch($yield = false)
    {
        if ($token = $this->scanner->consume("catch")) {
            
            if ($this->scanner->consume("(") &&
                ($param = $this->parseCatchParameter($yield)) &&
                $this->scanner->consume(")") &&
                $body = $this->parseBlock($yield)
            ) {

                $node = $this->createNode("CatchClause", $token);
                $node->setParam($param);
                $node->setBody($body);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses the catch parameter of a catch block in a try-catch statement
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node|null
     */
    protected function parseCatchParameter($yield = false)
    {
        if ($param = $this->parseIdentifier($yield)) {
            return $param;
        } elseif ($param = $this->parseBindingPattern($yield)) {
            return $param;
        }
        return null;
    }
    
    /**
     * Parses a finally block in a try-catch statement
     * 
     * @param bool $yield  Yield mode
     * 
     * @return Node\BlockStatement|null
     */
    protected function parseFinally($yield = false)
    {
        if ($this->scanner->consume("finally")) {
            
            if ($block = $this->parseBlock($yield)) {
                return $block;
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a countinue statement
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\ContinueStatement|null
     */
    protected function parseContinueStatement($yield = false)
    {
        if ($token = $this->scanner->consume("continue")) {
            
            $node = $this->createNode("ContinueStatement", $token);
            
            if ($this->scanner->noLineTerminators()) {
                if ($label = $this->parseIdentifier($yield)) {
                    $node->setLabel($label);
                }
            }
            
            $this->assertEndOfStatement();
            
            return $this->completeNode($node);
        }
        return null;
    }
    
    /**
     * Parses a break statement
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\BreakStatement|null
     */
    protected function parseBreakStatement($yield = false)
    {
        if ($token = $this->scanner->consume("break")) {
            
            $node = $this->createNode("BreakStatement", $token);
            
            if ($this->scanner->noLineTerminators()) {
                if ($label = $this->parseIdentifier($yield)) {
                    $node->setLabel($label);
                }
            }
            
            $this->assertEndOfStatement();
            
            return $this->completeNode($node);
        }
        return null;
    }
    
    /**
     * Parses a return statement
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\ReturnStatement|null
     */
    protected function parseReturnStatement($yield = false)
    {
        if ($token = $this->scanner->consume("return")) {
            
            $node = $this->createNode("ReturnStatement", $token);
            
            if ($this->scanner->noLineTerminators()) {
                if ($argument = $this->parseExpression(true, $yield)) {
                    $node->setArgument($argument);
                }
            }
            
            $this->assertEndOfStatement();
            
            return $this->completeNode($node);
        }
        return null;
    }
    
    /**
     * Parses a labelled statement
     * 
     * @param bool $yield  Yield mode
     * 
     * @return Node\LabeledStatement|null
     */
    protected function parseLabelledStatement($yield = false)
    {
        if ($label = $this->parseIdentifier($yield, ":")) {
            
            $this->scanner->consume(":");
                
            if (($body = $this->parseStatement($yield)) ||
                ($body = $this->parseFunctionOrGeneratorDeclaration(
                    $yield, false, false
                ))
            ) {

                $node = $this->createNode("LabeledStatement", $label);
                $node->setLabel($label);
                $node->setBody($body);
                return $this->completeNode($node);

            }

            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a throw statement
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\ThrowStatement|null
     */
    protected function parseThrowStatement($yield = false)
    {
        if ($token = $this->scanner->consume("throw")) {
            
            if ($this->scanner->noLineTerminators() &&
                ($argument = $this->parseExpression(true, $yield))
            ) {
                
                $this->assertEndOfStatement();
                $node = $this->createNode("ThrowStatement", $token);
                $node->setArgument($argument);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a with statement
     * 
     * @param bool $yield  Yield mode
     * 
     * @return Node\WithStatement|null
     */
    protected function parseWithStatement($yield = false)
    {
        if ($token = $this->scanner->consume("with")) {
            
            if ($this->scanner->consume("(") &&
                ($object = $this->parseExpression(true, $yield)) &&
                $this->scanner->consume(")") &&
                $body = $this->parseStatement($yield)
            ) {
            
                $node = $this->createNode("WithStatement", $token);
                $node->setObject($object);
                $node->setBody($body);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a switch statement
     * 
     * @param bool $yield  Yield mode
     * 
     * @return Node\SwitchStatement|null
     */
    protected function parseSwitchStatement($yield = false)
    {
        if ($token = $this->scanner->consume("switch")) {
            
            if ($this->scanner->consume("(") &&
                ($discriminant = $this->parseExpression(true, $yield)) &&
                $this->scanner->consume(")") &&
                ($cases = $this->parseCaseBlock($yield)) !== null
            ) {
            
                $node = $this->createNode("SwitchStatement", $token);
                $node->setDiscriminant($discriminant);
                $node->setCases($cases);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses the content of a switch statement
     * 
     * @param bool $yield  Yield mode
     * 
     * @return Node\SwitchCase[]|null
     */
    protected function parseCaseBlock($yield = false)
    {
        if ($this->scanner->consume("{")) {
            
            $parsedCasesAll = array(
                $this->parseCaseClauses($yield),
                $this->parseDefaultClause($yield),
                $this->parseCaseClauses($yield)
            );
            
            if ($this->scanner->consume("}")) {
                $cases = array();
                foreach ($parsedCasesAll as $parsedCases) {
                    if ($parsedCases) {
                        if (is_array($parsedCases)) {
                            $cases = array_merge($cases, $parsedCases);
                        } else {
                            $cases[] = $parsedCases;
                        }
                    }
                }
                return $cases;
            } elseif ($this->parseDefaultClause($yield)) {
                return $this->error(
                    "Multiple default clause in switch statement"
                );
            } else {
                return $this->error();
            }
        }
        return null;
    }
    
    /**
     * Parses cases in a switch statement
     * 
     * @param bool $yield  Yield mode
     * 
     * @return Node\SwitchCase[]|null
     */
    protected function parseCaseClauses($yield = false)
    {
        $cases = array();
        while ($case = $this->parseCaseClause($yield)) {
            $cases[] = $case;
        }
        return count($cases) ? $cases : null;
    }
    
    /**
     * Parses a case in a switch statement
     * 
     * @param bool $yield  Yield mode
     * 
     * @return Node\SwitchCase|null
     */
    protected function parseCaseClause($yield = false)
    {
        if ($token = $this->scanner->consume("case")) {
            
            if (($test = $this->parseExpression(true, $yield)) &&
                $this->scanner->consume(":")
            ) {

                $node = $this->createNode("SwitchCase", $token);
                $node->setTest($test);

                if ($consequent = $this->parseStatementList($yield)) {
                    $node->setConsequent($consequent);
                }

                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses default case in a switch statement
     * 
     * @param bool $yield  Yield mode
     * 
     * @return Node\SwitchCase|null
     */
    protected function parseDefaultClause($yield = false)
    {
        if ($token = $this->scanner->consume("default")) {
            
            if ($this->scanner->consume(":")) {

                $node = $this->createNode("SwitchCase", $token);
            
                if ($consequent = $this->parseStatementList($yield)) {
                    $node->setConsequent($consequent);
                }

                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses an expression statement
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\ExpressionStatement|null
     */
    protected function parseExpressionStatement($yield = false)
    {
        $lookahead = array("{", "function", "class", array("let", "["));
        if (!$this->scanner->isBefore($lookahead, true) &&
            $expression = $this->parseExpression(true, $yield)
        ) {
            
            $this->assertEndOfStatement();
            $node = $this->createNode("ExpressionStatement", $expression);
            $node->setExpression($expression);
            return $this->completeNode($node);
        }
        return null;
    }
    
    /**
     * Parses do-while, while, for, for-in and for-of statements
     * 
     * @param bool $yield  Yield mode
     * 
     * @return Node\Node|null
     */
    protected function parseIterationStatement($yield = false)
    {
        if ($token = $this->scanner->consume("do")) {
            
            if (($body = $this->parseStatement($yield)) &&
                $this->scanner->consume("while") &&
                $this->scanner->consume("(") &&
                ($test = $this->parseExpression(true, $yield)) &&
                $this->scanner->consume(")")
            ) {
                    
                $node = $this->createNode("DoWhileStatement", $token);
                $node->setBody($body);
                $node->setTest($test);
                return $this->completeNode($node);
            }
            return $this->error();
            
        } elseif ($token = $this->scanner->consume("while")) {
            
            if ($this->scanner->consume("(") &&
                ($test = $this->parseExpression(true, $yield)) &&
                $this->scanner->consume(")") &&
                $body = $this->parseStatement($yield)
            ) {
                    
                $node = $this->createNode("WhileStatement", $token);
                $node->setTest($test);
                $node->setBody($body);
                return $this->completeNode($node);
            }
            return $this->error();
            
        } elseif ($token = $this->scanner->consume("for")) {
            
            $hasBracket = $this->scanner->consume("(");
            $afterBracketState = $this->scanner->getState();
            
            if (!$hasBracket) {
                return $this->error();
            } elseif ($varToken = $this->scanner->consume("var")) {
                
                $state = $this->scanner->getState();
                
                if (($decl = $this->parseVariableDeclarationList(false, $yield)) &&
                    ($varEndPosition = $this->scanner->getPosition()) &&
                    $this->scanner->consume(";")
                ) {
                            
                    $init = $this->createNode(
                        "VariableDeclaration", $varToken
                    );
                    $init->setKind($init::KIND_VAR);
                    $init->setDeclarations($decl);
                    $init = $this->completeNode($init, $varEndPosition);
                    
                    $test = $this->parseExpression(true, $yield);
                    
                    if ($this->scanner->consume(";")) {
                        
                        $update = $this->parseExpression(true, $yield);
                        
                        if ($this->scanner->consume(")") &&
                            $body = $this->parseStatement($yield)
                        ) {
                            
                            $node = $this->createNode("ForStatement", $token);
                            $node->setInit($init);
                            $node->setTest($test);
                            $node->setUpdate($update);
                            $node->setBody($body);
                            return $this->completeNode($node);
                        }
                    }
                } else {
                    
                    $this->scanner->setState($state);
                    
                    if ($decl = $this->parseForBinding($yield)) {
                        
                        $left = $this->createNode(
                            "VariableDeclaration", $varToken
                        );
                        $left->setKind($left::KIND_VAR);
                        $left->setDeclarations(array($decl));
                        $left = $this->completeNode($left);
                        
                        if ($this->scanner->consume("in")) {
                            
                            if (($right = $this->parseExpression(true, $yield)) &&
                                $this->scanner->consume(")") &&
                                $body = $this->parseStatement($yield)
                            ) {
                                
                                $node = $this->createNode(
                                    "ForInStatement", $token
                                );
                                $node->setLeft($left);
                                $node->setRight($right);
                                $node->setBody($body);
                                return $this->completeNode($node);
                            }
                        } elseif ($this->scanner->consume("of")) {
                            
                            if (($right = $this->parseAssignmentExpression(true, $yield)) &&
                                $this->scanner->consume(")") &&
                                $body = $this->parseStatement($yield)
                            ) {
                                
                                $node = $this->createNode(
                                    "ForOfStatement", $token
                                );
                                $node->setLeft($left);
                                $node->setRight($right);
                                $node->setBody($body);
                                return $this->completeNode($node);
                            }
                        }
                    }
                }
            } elseif ($init = $this->parseForDeclaration($yield)) {
                
                if ($init && $this->scanner->consume("in")) {
                    if (($right = $this->parseExpression(true, $yield)) &&
                        $this->scanner->consume(")") &&
                        $body = $this->parseStatement($yield)
                    ) {
                        
                        $node = $this->createNode("ForInStatement", $token);
                        $node->setLeft($init);
                        $node->setRight($right);
                        $node->setBody($body);
                        return $this->completeNode($node);
                    }
                } elseif ($init && $this->scanner->consume("of")) {
                    if (($right = $this->parseAssignmentExpression(true, $yield)) &&
                        $this->scanner->consume(")") &&
                        $body = $this->parseStatement($yield)
                    ) {
                        
                        $node = $this->createNode("ForOfStatement", $token);
                        $node->setLeft($init);
                        $node->setRight($right);
                        $node->setBody($body);
                        return $this->completeNode($node);
                    }
                } else {
                    
                    $this->scanner->setState($afterBracketState);
                    if ($init = $this->parseLexicalDeclaration($yield)) {
                        
                        $test = $this->parseExpression(true, $yield);
                        if ($this->scanner->consume(";")) {
                                
                            $update = $this->parseExpression(true, $yield);
                            
                            if ($this->scanner->consume(")") &&
                                $body = $this->parseStatement($yield)
                            ) {
                                
                                $node = $this->createNode(
                                    "ForStatement", $token
                                );
                                $node->setInit($init);
                                $node->setTest($test);
                                $node->setUpdate($update);
                                $node->setBody($body);
                                return $this->completeNode($node);
                            }
                        }
                    }
                }
                
            } elseif (!$this->scanner->isBefore(array("let"))) {
                
                $state = $this->scanner->getState();
                $notBeforeSB = !$this->scanner->isBefore(
                    array(array("let", "[")), true
                );
                
                if ($notBeforeSB &&
                    (($init = $this->parseExpression(false, $yield)) || true) &&
                    $this->scanner->consume(";")
                ) {
                
                    $test = $this->parseExpression(true, $yield);
                    
                    if ($this->scanner->consume(";")) {
                            
                        $update = $this->parseExpression(true, $yield);
                        
                        if ($this->scanner->consume(")") &&
                            $body = $this->parseStatement($yield)
                        ) {
                            
                            $node = $this->createNode(
                                "ForStatement", $token
                            );
                            $node->setInit($init);
                            $node->setTest($test);
                            $node->setUpdate($update);
                            $node->setBody($body);
                            return $this->completeNode($node);
                        }
                    }
                } else {
                    
                    $this->scanner->setState($state);
                    $left = $this->parseLeftHandSideExpression($yield);
                    $left = $this->expressionToPattern($left);
                    
                    if ($notBeforeSB && $left &&
                        $this->scanner->consume("in")
                    ) {
                        
                        if (($right = $this->parseExpression(true, $yield)) &&
                            $this->scanner->consume(")") &&
                            $body = $this->parseStatement($yield)
                        ) {
                            
                            $node = $this->createNode(
                                "ForInStatement", $token
                            );
                            $node->setLeft($left);
                            $node->setRight($right);
                            $node->setBody($body);
                            return $this->completeNode($node);
                        }
                    } elseif ($left && $this->scanner->consume("of")) {
                        
                        if (($right = $this->parseAssignmentExpression(true, $yield)) &&
                            $this->scanner->consume(")") &&
                            $body = $this->parseStatement($yield)
                        ) {
                            
                            $node = $this->createNode(
                                "ForOfStatement", $token
                            );
                            $node->setLeft($left);
                            $node->setRight($right);
                            $node->setBody($body);
                            return $this->completeNode($node);
                        }
                    }
                }
            }
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses function or generator declaration
     * 
     * @param bool $yield          Yield mode
     * @param bool $default        Default mode
     * @param bool $allowGenerator True to allow parsing of generators
     * 
     * @return Node\FunctionDeclaration|null
     */
    protected function parseFunctionOrGeneratorDeclaration(
        $yield = false, $default = false, $allowGenerator = true
    ) {
        if ($token = $this->scanner->consume("function")) {
            
            $generator = $allowGenerator && $this->scanner->consume("*");
            $id = $this->parseIdentifier($yield);
            
            if (($default || $id) &&
                $this->scanner->consume("(") &&
                ($params = $this->parseFormalParameterList($generator)) !== null &&
                $this->scanner->consume(")") &&
                ($tokenBodyStart = $this->scanner->consume("{")) &&
                (($body = $this->parseFunctionBody($generator)) || true) &&
                $this->scanner->consume("}")
            ) {
                
                $body->setStartPosition(
                    $tokenBodyStart->getLocation()->getStart()
                );
                $body->setEndPosition($this->scanner->getPosition());
                $node = $this->createNode("FunctionDeclaration", $token);
                if ($id) {
                    $node->setId($id);
                }
                $node->setParams($params);
                $node->setBody($body);
                $node->setGenerator($generator);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses function or generator expression
     * 
     * @return Node\FunctionExpression|null
     */
    protected function parseFunctionOrGeneratorExpression()
    {
        if ($token = $this->scanner->consume("function")) {
            
            $generator = (bool) $this->scanner->consume("*");
            $id = $this->parseIdentifier(false);
            
            if ($this->scanner->consume("(") &&
                ($params = $this->parseFormalParameterList($generator)) !== null &&
                $this->scanner->consume(")") &&
                ($tokenBodyStart = $this->scanner->consume("{")) &&
                (($body = $this->parseFunctionBody($generator)) || true) &&
                $this->scanner->consume("}")
            ) {
                
                $body->setStartPosition(
                    $tokenBodyStart->getLocation()->getStart()
                );
                $body->setEndPosition($this->scanner->getPosition());
                $node = $this->createNode("FunctionExpression", $token);
                $node->setId($id);
                $node->setParams($params);
                $node->setBody($body);
                $node->setGenerator($generator);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses yield statement
     * 
     * @param bool $in In mode
     * 
     * @return Node\YieldExpression|null
     */
    protected function parseYieldExpression($in = false)
    {
        if ($token = $this->scanner->consume("yield")) {
            
            $node = $this->createNode("YieldExpression", $token);
            if ($this->scanner->noLineTerminators()) {
                
                $delegate = $this->scanner->consume("*");
                if ($argument = $this->parseAssignmentExpression($in, true)) {
                    $node->setArgument($argument);
                    $node->setDelegate($delegate);
                }
            }
            
            return $this->completeNode($node);
        }
        return null;
    }
    
    /**
     * Parses a parameter list
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\Node[]|null
     */
    protected function parseFormalParameterList($yield = false)
    {
        $valid = true;
        $list = array();
        while ($param = $this->parseBindingElement($yield)) {
            $list[] = $param;
            $valid = true;
            if ($this->scanner->consume(",")) {
                if ($restParam = $this->parseBindingRestElement($yield)) {
                    $list[] = $restParam;
                    break;
                }
                $valid = false;
            } else {
                break;
            }
        }
        if (!$valid) {
            return $this->error();
        }
        return $list;
    }
    
    /**
     * Parses a function body
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\BlockStatement[]|null
     */
    protected function parseFunctionBody($yield = false)
    {
        $body = $this->isolateContext(
            array("allowReturn" => true),
            "parseStatementList",
            array($yield, true)
        );
        $node = $this->createNode(
            "BlockStatement", $body ? $body : $this->scanner->getPosition()
        );
        if ($body) {
            $node->setBody($body);
        }
        return $this->completeNode($node);
    }
    
    /**
     * Parses a class declaration
     * 
     * @param bool $yield   Yield mode
     * @param bool $default Default mode
     * 
     * @return Node\ClassDeclaration|null
     */
    protected function parseClassDeclaration($yield = false, $default = false)
    {
        if ($token = $this->scanner->consume("class")) {
            
            $id = $this->parseIdentifier($yield);
            if (($default || $id) &&
                $tail = $this->parseClassTail($yield)
            ) {
                
                $node = $this->createNode("ClassDeclaration", $token);
                if ($id) {
                    $node->setId($id);
                }
                if ($tail[0]) {
                    $node->setSuperClass($tail[0]);
                }
                $node->setBody($tail[1]);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a class expression
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\ClassExpression|null
     */
    protected function parseClassExpression($yield = false)
    {
        if ($token = $this->scanner->consume("class")) {
            $id = $this->parseIdentifier($yield);
            $tail = $this->parseClassTail($yield);
            $node = $this->createNode("ClassExpression", $token);
            if ($id) {
                $node->setId($id);
            }
            if ($tail[0]) {
                $node->setSuperClass($tail[0]);
            }
            $node->setBody($tail[1]);
            return $this->completeNode($node);
        }
        return null;
    }
    
    /**
     * Parses the code that comes after the class keyword and class name. The
     * return value is an array where the first item is the extendend class, if
     * any, and the second value is the class body
     * 
     * @param bool $yield Yield mode
     * 
     * @return array|null
     */
    protected function parseClassTail($yield = false)
    {
        $heritage = $this->parseClassHeritage($yield);
        if ($token = $this->scanner->consume("{")) {
            
            $body = $this->parseClassBody($yield);
            if ($this->scanner->consume("}")) {
                $body->setStartPosition($token->getLocation()->getStart());
                $body->setEndPosition($this->scanner->getPosition());
                return array($heritage, $body);
            }
        }
        return $this->error();
    }
    
    /**
     * Parses the class extends part
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\Node|null
     */
    protected function parseClassHeritage($yield = false)
    {
        if ($this->scanner->consume("extends")) {
            
            if ($superClass = $this->parseLeftHandSideExpression($yield)) {
                return $superClass;
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses the class body
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\ClassBody|null
     */
    protected function parseClassBody($yield = false)
    {
        $body = $this->parseClassElementList($yield);
        $node = $this->createNode(
            "ClassBody", $body ? $body : $this->scanner->getPosition()
        );
        if ($body) {
            $node->setBody($body);
        }
        return $this->completeNode($node);
    }
    
    /**
     * Parses class elements list
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\MethodDefinition[]|null
     */
    protected function parseClassElementList($yield = false)
    {
        $items = array();
        while ($item = $this->parseClassElement($yield)) {
            if ($item !== true) {
                $items[] = $item;
            }
        }
        return count($items) ? $items : null;
    }
    
    /**
     * Parses a class elements
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\MethodDefinition|null
     */
    protected function parseClassElement($yield = false)
    {
        if ($this->scanner->consume(";")) {
            return true;
        }
        
        $staticToken = $this->scanner->consume("static");
        if ($def = $this->parseMethodDefinition($yield)) {
            if ($staticToken) {
                $def->setStatic(true);
                $def->setStartPosition($staticToken->getLocation()->getStart());
            }
            return $def;
        } elseif ($staticToken) {
            return $this->error();
        }
        
        return null;
    }
    
    /**
     * Parses a let or const declaration
     * 
     * @param bool $in    In mode
     * @param bool $yield Yield mode
     * 
     * @return Node\VariableDeclaration|null
     */
    protected function parseLexicalDeclaration($in = false, $yield = false)
    {
        if ($token = $this->scanner->consumeOneOf(array("let", "const"))) {
            
            $declarations = $this->charSeparatedListOf(
                "parseVariableDeclaration",
                array($in, $yield)
            );
            
            if ($declarations) {
                $this->assertEndOfStatement();
                $node = $this->createNode("VariableDeclaration", $token);
                $node->setKind($token->getValue());
                $node->setDeclarations($declarations);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a var declaration
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\VariableDeclaration|null
     */
    protected function parseVariableStatement($yield = false)
    {
        if ($token = $this->scanner->consume("var")) {
            
            $declarations = $this->parseVariableDeclarationList(true, $yield);
            if ($declarations) {
                $this->assertEndOfStatement();
                $node = $this->createNode("VariableDeclaration", $token);
                $node->setKind($node::KIND_VAR);
                $node->setDeclarations($declarations);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses an variable declarations
     * 
     * @param bool $in    In mode
     * @param bool $yield Yield mode
     * 
     * @return Node\VariableDeclarator[]|null
     */
    protected function parseVariableDeclarationList($in = false, $yield = false)
    {
        return $this->charSeparatedListOf(
            "parseVariableDeclaration",
            array($in, $yield)
        );
    }
    
    /**
     * Parses a variable declarations
     * 
     * @param bool $in    In mode
     * @param bool $yield Yield mode
     * 
     * @return Node\VariableDeclarator|null
     */
    protected function parseVariableDeclaration($in = false, $yield = false)
    {
        if ($id = $this->parseIdentifier($yield)) {
            
            $node = $this->createNode("VariableDeclarator", $id);
            $node->setId($id);
            if ($init = $this->parseInitializer($in, $yield)) {
                $node->setInit($init);
            }
            return $this->completeNode($node);
            
        } elseif ($id = $this->parseBindingPattern($yield)) {
            
            if ($init = $this->parseInitializer($in, $yield)) {
                $node = $this->createNode("VariableDeclarator", $id);
                $node->setId($id);
                $node->setInit($init);
                return $this->completeNode($node);
            }
            
        }
        return null;
    }
    
    /**
     * Parses a let or const declaration in a for statement definition
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\VariableDeclaration|null
     */
    protected function parseForDeclaration($yield = false)
    {
        if ($token = $this->scanner->consumeOneOf(array("let", "const"))) {
            
            if ($declaration = $this->parseForBinding($yield)) {

                $node = $this->createNode("VariableDeclaration", $token);
                $node->setKind($token->getValue());
                $node->setDeclarations(array($declaration));
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a binding pattern or an identifier that come after a const or let
     * declaration in a for statement definition
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\VariableDeclarator|null
     */
    protected function parseForBinding($yield = false)
    {
        if (($id = $this->parseIdentifier($yield)) ||
            ($id = $this->parseBindingPattern($yield))
        ) {
            
            $node = $this->createNode("VariableDeclarator", $id);
            $node->setId($id);
            return $this->completeNode($node);
        }
        return null;
    }
    
    /**
     * Parses a module item
     * 
     * @return Node\Node|null
     */
    protected function parseModuleItem()
    {
        if ($item = $this->parseImportDeclaration()) {
            return $item;
        } elseif ($item = $this->parseExportDeclaration()) {
            return $item;
        } elseif ($item = $this->parseStatementListItem()) {
            return $item;
        }
        return null;
    }
    
    /**
     * Parses the from keyword and the following string in import and export
     * declarations
     * 
     * @return Node\Literal|null
     */
    protected function parseFromClause()
    {
        if ($this->scanner->consume("from")) {
            if ($spec = $this->parseStringLiteral()) {
                return $spec;
            }
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses an export declaration
     * 
     * @return Node\ModuleDeclaration|null
     */
    protected function parseExportDeclaration()
    {
        if ($token = $this->scanner->consume("export")) {
            
            if ($this->scanner->consume("*")) {
                
                if ($source = $this->parseFromClause()) {
                    $this->assertEndOfStatement();
                    $node = $this->createNode("ExportAllDeclaration", $token);
                    $node->setSource($source);
                    return $this->completeNode($node);
                }
                
            } elseif ($this->scanner->consume("default")) {
                
                if (($declaration = $this->parseFunctionOrGeneratorDeclaration(false, true)) ||
                    ($declaration = $this->parseClassDeclaration(false, true))
                ) {
                    
                    $node = $this->createNode("ExportDefaultDeclaration", $token);
                    $node->setDeclaration($declaration);
                    return $this->completeNode($node);
                    
                } elseif (!$this->scanner->isBefore(array("function", "class")) &&
                    ($declaration = $this->parseAssignmentExpression(true))
                ) {
                    
                    $this->assertEndOfStatement();
                    $node = $this->createNode(
                        "ExportDefaultDeclaration", $token
                    );
                    $node->setDeclaration($declaration);
                    return $this->completeNode($node);
                }
                
            } elseif (($specifiers = $this->parseExportClause()) !== null) {
                
                $node = $this->createNode("ExportNamedDeclaration", $token);
                $node->setSpecifiers($specifiers);
                if ($source = $this->parseFromClause()) {
                    $node->setSource($source);
                }
                $this->assertEndOfStatement();
                return $this->completeNode($node);

            } elseif (($dec = $this->parseVariableStatement()) ||
                $dec = $this->parseDeclaration()
            ) {

                $node = $this->createNode("ExportNamedDeclaration", $token);
                $node->setDeclaration($dec);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses an export clause
     * 
     * @return Node\ExportSpecifier[]|null
     */
    protected function parseExportClause()
    {
        if ($this->scanner->consume("{")) {
            
            $list = array();
            while ($spec = $this->parseExportSpecifier()) {
                $list[] = $spec;
                if (!$this->scanner->consume(",")) {
                    break;
                }
            }
            
            if ($this->scanner->consume("}")) {
                return $list;
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses an export specifier
     * 
     * @return Node\ExportSpecifier|null
     */
    protected function parseExportSpecifier()
    {
        if ($local = $this->parseIdentifier()) {
            
            $node = $this->createNode("ExportSpecifier", $local);
            $node->setLocal($local);
            
            if ($this->scanner->consume("as")) {
                
                if ($exported = $this->parseIdentifier()) {
                    $node->setExported($exported);
                    return $this->completeNode($node);
                }
                
                return $this->error();
            } else {
                $node->setExported($local);
                return $this->completeNode($node);
            }
        }
        return null;
    }
    
    /**
     * Parses an import declaration
     * 
     * @return Node\ModuleDeclaration|null
     */
    protected function parseImportDeclaration()
    {
        if ($token = $this->scanner->consume("import")) {
            
            if ($source = $this->parseStringLiteral()) {
                
                $this->assertEndOfStatement();
                $node = $this->createNode("ImportDeclaration", $token);
                $node->setSource($source);
                return $this->completeNode($node);
                
            } elseif (($specifiers = $this->parseImportClause()) &&
                $source = $this->parseFromClause()
            ) {
                
                $this->assertEndOfStatement();
                $node = $this->createNode("ImportDeclaration", $token);
                $node->setSpecifiers($specifiers);
                $node->setSource($source);
                
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses an import clause
     * 
     * @return Node\ModuleSpecifier|null
     */
    protected function parseImportClause()
    {
        if ($spec = $this->parseNameSpaceImport()) {
            return array($spec);
        } elseif ($specs = $this->parseNamedImports()) {
            return $specs;
        } elseif ($spec = $this->parseIdentifier(false)) {
            
            $node = $this->createNode("ImportDefaultSpecifier", $spec);
            $node->setLocal($spec);
            $ret = array($this->completeNode($node));
            
            if ($this->scanner->consume(",")) {
                
                if ($spec = $this->parseNameSpaceImport()) {
                    $ret[] = $spec;
                    return $ret;
                } elseif ($specs = $this->parseNamedImports()) {
                    $ret = array_merge($ret, $specs);
                    return $ret;
                }
                
                return $this->error();
            } else {
                return $ret;
            }
        }
        return null;
    }
    
    /**
     * Parses a namespace import
     * 
     * @return Node\ImportNamespaceSpecifier|null
     */
    protected function parseNameSpaceImport()
    {
        if ($token = $this->scanner->consume("*")) {
            
            if ($this->scanner->consume("as") &&
                $local = $this->parseIdentifier(false)
            ) {
                $node = $this->createNode("ImportNamespaceSpecifier", $token);
                $node->setLocal($local);
                return $this->completeNode($node);  
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a named imports
     * 
     * @return Node\ImportSpecifier[]|null
     */
    protected function parseNamedImports()
    {
        if ($this->scanner->consume("{")) {
            
            $list = array();
            while ($spec = $this->parseImportSpecifier()) {
                $list[] = $spec;
                if (!$this->scanner->consume(",")) {
                    break;
                }
            }
            
            if ($this->scanner->consume("}")) {
                return $list;
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses an import specifier
     * 
     * @return Node\ImportSpecifier|null
     */
    protected function parseImportSpecifier()
    {
        if ($imported = $this->parseIdentifier()) {
            
            $node = $this->createNode("ImportSpecifier", $imported);
            $node->setImported($imported);
            if ($this->scanner->consume("as")) {
                
                if ($local = $this->parseIdentifier()) {
                    $node->setLocal($local);
                    return $this->completeNode($node);
                }
                
                return $this->error();
            } else {
                $node->setLocal($imported);
                return $this->completeNode($node);
            }
        }
        return null;
    }
    
    /**
     * Parses a binding pattern
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\ArrayPattern|Node\ObjectPattern|null
     */
    protected function parseBindingPattern($yield = false)
    {
        if ($pattern = $this->parseObjectBindingPattern($yield)) {
            return $pattern;
        } elseif ($pattern = $this->parseArrayBindingPattern($yield)) {
            return $pattern;
        }
        return null;
    }
    
    /**
     * Parses an elisions sequence. It returns the number of elisions or null
     * if no elision has been found
     * 
     * @return int
     */
    protected function parseElision()
    {
        $count = 0;
        while ($this->scanner->consume(",")) {
            $count ++;
        }
        return $count ? $count : null;
    }
    
    /**
     * Parses an array binding pattern
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\ArrayPattern|null
     */
    protected function parseArrayBindingPattern($yield = false)
    {
        if ($token = $this->scanner->consume("[")) {
            
            $elements = array();
            while (true) {
                if ($elision = $this->parseElision()) {
                    $elements = array_merge(
                        $elements, array_fill(0, $elision, null)
                    );
                }
                if ($element = $this->parseBindingElement($yield)) {
                    $elements[] = $element;
                    if (!$this->scanner->consume(",")) {
                        break;
                    }
                } elseif ($rest = $this->parseBindingRestElement($yield)) {
                    $elements[] = $rest;
                    break;
                } else {
                    break;
                }
            }
            
            if ($this->scanner->consume("]")) {
                $node = $this->createNode("ArrayPattern", $token);
                $node->setElements($elements);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a rest element
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\RestElement|null
     */
    protected function parseBindingRestElement($yield = false)
    {
        if ($token = $this->scanner->consume("...")) {
            
            if ($argument = $this->parseIdentifier($yield)) {
                $node = $this->createNode("RestElement", $token);
                $node->setArgument($argument);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a binding element
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\AssignmentPattern|Node\Identifier|null
     */
    protected function parseBindingElement($yield = false)
    {
        if ($el = $this->parseSingleNameBinding($yield)) {
            return $el;
        } elseif ($left = $this->parseBindingPattern($yield)) {
            
            if ($right = $this->parseInitializer(true, $yield)) {
                $node = $this->createNode("AssignmentPattern", $left);
                $node->setLeft($left);
                $node->setRight($right);
                return $this->completeNode($node);
            } else {
                return $left;
            }
        }
        return null;
    }
    
    /**
     * Parses single name binding
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\AssignmentPattern|Node\Identifier|null
     */
    protected function parseSingleNameBinding($yield = false)
    {
        if ($left = $this->parseIdentifier($yield)) {
            if ($right = $this->parseInitializer(true, $yield)) {
                $node = $this->createNode("AssignmentPattern", $left);
                $node->setLeft($left);
                $node->setRight($right);
                return $this->completeNode($node);
            } else {
                return $left;
            }
        }
        return null;
    }
    
    /**
     * Parses a property name. The returned value is an array where there first
     * element is the property name and the second element is a boolean
     * indicating if it's a computed property
     * 
     * @param bool $yield Yield mode
     * 
     * @return array|null
     */
    protected function parsePropertyName($yield = false)
    {
        if ($token = $this->scanner->consume("[")) {
            
            if (($name = $this->parseAssignmentExpression(true, $yield)) &&
                $this->scanner->consume("]")
            ) {
                return array($name, true, $token);
            }
            
            return $this->error();
        } elseif ($name = $this->parseIdentifier($yield)) {
            return array($name, false);
        } elseif ($name = $this->parseStringLiteral()) {
            return array($name, false);
        } elseif ($name = $this->parseNumericLiteral()) {
            return array($name, false);
        }
        return null;
    }
    
    /**
     * Parses a method definition
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\MethodDefinition|null
     */
    protected function parseMethodDefinition($yield = false)
    {
        $state = $this->scanner->getState();
        $generator = false;
        $position = null;
        $error = false;
        $kind = Node\MethodDefinition::KIND_METHOD;
        if ($token = $this->scanner->consume("get")) {
            $position = $token;
            $kind = Node\MethodDefinition::KIND_GET;
            $error = true;
        } elseif ($token = $this->scanner->consume("set")) {
            $position = $token;
            $kind = Node\MethodDefinition::KIND_SET;
            $error = true;
        } elseif ($token = $this->scanner->consume("*")) {
            $position = $token;
            $error = true;
            $generator = true;
        }
        
        //Handle the case where get and set are methods name and not the
        //definition of a getter/setter
        if ($kind !== Node\MethodDefinition::KIND_METHOD &&
            $this->scanner->consume("(")
        ) {
            $this->scanner->setState($state);
            $kind = Node\MethodDefinition::KIND_METHOD;
            $error = false;
        }
        
        if ($prop = $this->parsePropertyName($yield)) {
            
            if (!$position) {
                $position = isset($prop[2]) ? $prop[2] : $prop[0];
            }
            if ($tokenFn = $this->scanner->consume("(")) {
                
                $error = true;
                $params = array();
                if ($kind === Node\MethodDefinition::KIND_SET) {
                    if ($params = $this->parseBindingElement()) {
                        $params = array($params);
                    }
                } elseif ($kind === Node\MethodDefinition::KIND_METHOD) {
                    $params = $this->parseFormalParameterList();
                }

                if ($params !== null &&
                    $this->scanner->consume(")") &&
                    ($tokenBodyStart = $this->scanner->consume("{")) &&
                    (($body = $this->parseFunctionBody($generator)) || true) &&
                    $this->scanner->consume("}")
                ) {

                    if ($prop[0] instanceof Node\Identifier &&
                        $prop[0]->getName() === "constructor"
                    ) {
                        $kind = Node\MethodDefinition::KIND_CONSTRUCTOR;
                    }

                    $body->setStartPosition(
                        $tokenBodyStart->getLocation()->getStart()
                    );
                    $body->setEndPosition($this->scanner->getPosition());
                    
                    $nodeFn = $this->createNode("FunctionExpression", $tokenFn);
                    $nodeFn->setParams($params);
                    $nodeFn->setBody($body);
                    $nodeFn->setGenerator($generator);

                    $node = $this->createNode("MethodDefinition", $position);
                    $node->setKey($prop[0]);
                    $node->setValue($this->completeNode($nodeFn));
                    $node->setKind($kind);
                    $node->setComputed($prop[1]);
                    return $this->completeNode($node);
                }
            }
        }
        
        if ($error) {
            return $this->error();
        } else {
            $this->scanner->setState($state);
        }
        return null;
    }
    
    /**
     * Parses parameters in an arrow function. If the parameters are wrapped in
     * round brackets, the returned value is an array where the first element
     * is the parameters list and the second element is the open round brackets,
     * this is needed to know the start position
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\Identifier|array|null
     */
    protected function parseArrowParameters($yield = false)
    {
        if ($param = $this->parseIdentifier($yield, "=>")) {
            return $param;
        } elseif ($token = $this->scanner->consume("(")) {
            
            $params = $this->parseFormalParameterList($yield);
            
            if ($params !== null && $this->scanner->consume(")")) {
                return array($params, $token);
            }
        }
        return null;
    }
    
    /**
     * Parses the body of an arrow function. The returned value is an array
     * where the first element is the function body and the second element is
     * a boolean indicating if the body is wrapped in curly braces
     * 
     * @param bool $in In mode
     * 
     * @return array|null
     */
    protected function parseConciseBody($in = false)
    {
        if ($token = $this->scanner->consume("{")) {
            
            if (($body = $this->parseFunctionBody()) &&
                $this->scanner->consume("}")
            ) {
                $body->setStartPosition($token->getLocation()->getStart());
                $body->setEndPosition($this->scanner->getPosition());
                return array($body, false);
            }
            
            return $this->error();
        } elseif (!$this->scanner->isBefore(array("{")) &&
            $body = $this->parseAssignmentExpression($in)
        ) {
            return array($body, true);
        }
        return null;
    }
    
    /**
     * Parses an arrow function
     * 
     * @param bool $in    In mode
     * @param bool $yield Yield mode
     * 
     * @return Node\ArrowFunctionExpression|null
     */
    protected function parseArrowFunction($in = false, $yield = false)
    {
        $state = $this->scanner->getState();
        if (($params = $this->parseArrowParameters($yield)) !== null) {
            
            if ($this->scanner->noLineTerminators() &&
                $this->scanner->consume("=>")
            ) {
                
                if ($body = $this->parseConciseBody($in)) {
                    if (is_array($params)) {
                        $pos = $params[1];
                        $params = $params[0];
                    } else {
                        $pos = $params;
                        $params = array($params);
                    }
                    $node = $this->createNode("ArrowFunctionExpression", $pos);
                    $node->setParams($params);
                    $node->setBody($body[0]);
                    $node->setExpression($body[1]);
                    return $this->completeNode($node);
                }
            
                return $this->error();
            }
        }
        $this->scanner->setState($state);
        return null;
    }
    
    /**
     * Parses an object literal
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\ObjectExpression|null
     */
    protected function parseObjectLiteral($yield = false)
    {
        if ($token = $this->scanner->consume("{")) {
            
            $properties = array();
            while ($prop = $this->parsePropertyDefinition($yield)) {
                $properties[] = $prop;
                if (!$this->scanner->consume(",")) {
                    break;
                }
            }
            
            if ($this->scanner->consume("}")) {
                
                $node = $this->createNode("ObjectExpression", $token);
                if ($properties) {
                    $node->setProperties($properties);
                }
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a property in an object literal
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\Property|null
     */
    protected function parsePropertyDefinition($yield = false)
    {
        $state = $this->scanner->getState();
        if (($property = $this->parsePropertyName($yield)) &&
            $this->scanner->consume(":")
        ) {

            if ($value = $this->parseAssignmentExpression(true, $yield)) {
                $startPos = isset($property[2]) ? $property[2] : $property[0];
                $node = $this->createNode("Property", $startPos);
                $node->setKey($property[0]);
                $node->setValue($value);
                $node->setComputed($property[1]);
                return $this->completeNode($node);
            }

            return $this->error();
            
        }
        
        $this->scanner->setState($state);
        if ($property = $this->parseMethodDefinition($yield)) {

            $node = $this->createNode("Property", $property);
            $node->setKey($property->getKey());
            $node->setValue($property->getValue());
            $node->setComputed($property->getComputed());
            $kind = $property->getKind();
            if ($kind !== Node\MethodDefinition::KIND_GET &&
                $kind !== Node\MethodDefinition::KIND_SET
            ) {
                $node->setMethod(true);
                $node->setKind(Node\Property::KIND_INIT);
            } else {
                $node->setKind($kind);
            }
            return $this->completeNode($node);
            
        } elseif ($key = $this->parseIdentifier($yield)) {
            
            $node = $this->createNode("Property", $key);
            $node->setShorthand(true);
            $node->setKey($key);
            $node->setValue(
                ($value = $this->parseInitializer(true, $yield)) ?
                $value :
                $key
            );
            return $this->completeNode($node);
            
        }
        return null;
    }
    
    /**
     * Parses an itlializer
     * 
     * @param bool $in    In mode
     * @param bool $yield Yield mode
     * 
     * @return Node\Node|null
     */
    protected function parseInitializer($in = false, $yield = false)
    {
        if ($this->scanner->consume("=")) {
            
            if ($value = $this->parseAssignmentExpression($in, $yield)) {
                return $value;
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses an object binding pattern
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\ObjectPattern|null
     */
    protected function parseObjectBindingPattern($yield = false)
    {
        if ($token = $this->scanner->consume("{")) {
            
            $properties = array();
            while ($prop = $this->parseBindingProperty($yield)) {
                $properties[] = $prop;
                if (!$this->scanner->consume(",")) {
                    break;
                }
            }
            
            if ($this->scanner->consume("}")) {
                $node = $this->createNode("ObjectPattern", $token);
                if ($properties) {
                    $node->setProperties($properties);
                }
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a property in an object binding pattern
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\AssignmentProperty|null
     */
    protected function parseBindingProperty($yield = false)
    {
        $state = $this->scanner->getState();
        if (($key = $this->parsePropertyName($yield)) &&
            $this->scanner->consume(":")
        ) {
            
            if ($value = $this->parseBindingElement($yield)) {
                $startPos = isset($key[2]) ? $key[2] : $key[0];
                $node = $this->createNode("AssignmentProperty", $startPos);
                $node->setKey($key[0]);
                $node->setComputed($key[1]);
                $node->setValue($value);
                return $this->completeNode($node);
            }
            
            return $this->error();
            
        }
            
        $this->scanner->setState($state);
        if ($property = $this->parseSingleNameBinding($yield)) {
            
            $node = $this->createNode("AssignmentProperty", $property);
            $node->setShorthand(true);
            if ($property instanceof Node\AssignmentPattern) {
                $node->setKey($property->getLeft());
            } else {
                $node->setKey($property);
            }
            $node->setValue($property);
            return $this->completeNode($node);
        }
        return null;
    }
    
    /**
     * Parses an expression
     * 
     * @param bool $in    In mode
     * @param bool $yield Yield mode
     * 
     * @return Node\Node|null
     */
    protected function parseExpression($in = false, $yield = false)
    {
        $list = $this->charSeparatedListOf(
            "parseAssignmentExpression",
            array($in, $yield)
        );
        
        if (!$list) {
            return null;
        } elseif (count($list) === 1) {
            return $list[0];
        } else {
            $node = $this->createNode("SequenceExpression", $list);
            $node->setExpressions($list);
            return $this->completeNode($node);
        }
    }
    
    /**
     * Parses an assignment expression
     * 
     * @param bool $in    In mode
     * @param bool $yield Yield mode
     * 
     * @return Node\Node|null
     */
    protected function parseAssignmentExpression($in = false, $yield = false)
    {
        if ($expr = $this->parseArrowFunction($in, $yield)) {
            return $expr;
        } elseif ($yield && $expr = $this->parseYieldExpression($in)) {
            return $expr;
        } elseif ($expr = $this->parseConditionalExpression($in, $yield)) {
            
            $exprTypes = array(
                "ConditionalExpression", "LogicalExpression",
                "BinaryExpression", "UpdateExpression", "UnaryExpression"
            );
            
            if (!in_array($expr->getType(), $exprTypes)) {
                
                $operators = $this->assignmentOperators;
                if ($operator = $this->scanner->consumeOneOf($operators)) {
                    
                    $right = $this->parseAssignmentExpression($in, $yield);
                    if ($right) {
                        $node = $this->createNode(
                            "AssignmentExpression", $expr
                        );
                        $node->setLeft($this->expressionToPattern($expr));
                        $node->setOperator($operator->getValue());
                        $node->setRight($right);
                        return $this->completeNode($node);
                    }
                    return $this->error();
                }
            }
            return $expr;
        }
        return null;
    }
    
    /**
     * Parses a conditional expression
     * 
     * @param bool $in    In mode
     * @param bool $yield Yield mode
     * 
     * @return Node\Node|null
     */
    protected function parseConditionalExpression($in = false, $yield = false)
    {
        if ($test = $this->parseLogicalBinaryExpression($in, $yield)) {
            
            if ($this->scanner->consume("?")) {
                
                $consequent = $this->parseAssignmentExpression($in, $yield);
                if ($consequent && $this->scanner->consume(":") &&
                    $alternate = $this->parseAssignmentExpression($in, $yield)
                ) {
                
                    $node = $this->createNode("ConditionalExpression", $test);
                    $node->setTest($test);
                    $node->setConsequent($consequent);
                    $node->setAlternate($alternate);
                    return $this->completeNode($node);
                }
                
                return $this->error();
            } else {
                return $test;
            }
        }
        return null;
    }
    
    /**
     * Parses a logical or a binary expression
     * 
     * @param bool $in    In mode
     * @param bool $yield Yield mode
     * 
     * @return Node\Node|null
     */
    protected function parseLogicalBinaryExpression($in = false, $yield = false)
    {
        $operators = $this->logicalBinaryOperators;
        if (!$in) {
            unset($operators["in"]);
        }
        
        if (!($exp = $this->parseUnaryExpression($yield))) {
            return null;
        }
        
        $list = array($exp);
        while ($token = $this->scanner->consumeOneOf(array_keys($operators))) {
            if (!($exp = $this->parseUnaryExpression($yield))) {
                return $this->error();
            }
            $list[] = $token->getValue();
            $list[] = $exp;
        }
        
        $len = count($list);
        if ($len > 1) {
            $maxGrade = max($operators);
            for ($grade = $maxGrade; $grade >= 0; $grade--) {
                $class = $grade < 2 ? "LogicalExpression" : "BinaryExpression";
                for ($i = 1; $i < $len; $i += 2) {
                    if ($operators[$list[$i]] === $grade) {
                        $node = $this->createNode($class, $list[$i - 1]);
                        $node->setLeft($list[$i - 1]);
                        $node->setOperator($list[$i]);
                        $node->setRight($list[$i + 1]);
                        $node = $this->completeNode(
                            $node, $list[$i + 1]->getLocation()->getEnd()
                        );
                        array_splice($list, $i - 1, 3, array($node));
                        $i -= 2;
                        $len = count($list);
                    }
                }
            }
        }
        return $list[0];
    }
    
    /**
     * Parses a unary expression
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\Node|null
     */
    protected function parseUnaryExpression($yield = false)
    {
        if ($expr = $this->parsePostfixExpression($yield)) {
            return $expr;
        } elseif ($token = $this->scanner->consumeOneOf($this->unaryOperators)) {
            if ($argument = $this->parseUnaryExpression($yield)) {
                $op = $token->getValue();
                if ($op === "++" || $op === "--") {
                    $node = $this->createNode("UpdateExpression", $token);
                    $node->setPrefix(true);
                } else {
                    $node = $this->createNode("UnaryExpression", $token);
                }
                $node->setOperator($op);
                $node->setArgument($argument);
                return $this->completeNode($node);
            }

            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a postfix expression
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\Node|null
     */
    protected function parsePostfixExpression($yield = false)
    {
        if ($argument = $this->parseLeftHandSideExpression($yield)) {
            
            if ($this->scanner->noLineTerminators() &&
                $token = $this->scanner->consumeOneOf($this->postfixOperators)
            ) {
                
                $node = $this->createNode("UpdateExpression", $argument);
                $node->setOperator($token->getValue());
                $node->setArgument($argument);
                return $this->completeNode($node);
            }
            
            return $argument;
        }
        return null;
    }
    
    /**
     * Parses a left hand side expression
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\Node|null
     */
    protected function parseLeftHandSideExpression($yield = false)
    {
        $object = null;
        $newTokens = array();
        
        //Parse all occurences of "new"
        if ($newToken = $this->scanner->isBefore(array("new"))) {
            while ($newToken = $this->scanner->consume("new")) {
                if ($this->scanner->consume(".")) {
                    //new.target
                    if ($this->scanner->consume("target")) {
                    
                        $node = $this->createNode("MetaProperty", $newToken);
                        $node->setMeta("new");
                        $node->setProperty("target");
                        $object = $this->completeNode($node);
                        break;
                    
                    } else {
                        return $this->error();
                    }
                }
                $newTokens[] = $newToken;
            }
        }
        
        $newTokensCount = count($newTokens);
        
        if (!$object &&
            !($object = $this->parseSuperPropertyOrCall($yield)) &&
            !($object = $this->parsePrimaryExpression($yield))
        ) {
            
            if ($newTokensCount) {
                return $this->error();
            }
            return null;
        }
        
        $valid = true;
        $properties = array();
        while (true) {
            if ($this->scanner->consume(".")) {
                if ($property = $this->parseIdentifier()) {
                    $properties[] = array(
                        "type"=> "id",
                        "info" => $property
                    );
                } else {
                    $valid = false;
                    break;
                }
            } elseif ($this->scanner->consume("[")) {
                if (($property = $this->parseExpression(true, $yield)) &&
                    $this->scanner->consume("]")
                ) {
                    $properties[] = array(
                        "type" => "computed",
                        "info" => array(
                            $property, $this->scanner->getPosition()
                        )
                    );
                } else {
                    $valid = false;
                    break;
                }
            } elseif ($property = $this->parseTemplateLiteral($yield)) {
                $properties[] = array(
                    "type"=> "template",
                    "info" => $property
                );
            } elseif (($args = $this->parseArguments($yield)) !== null) {
                $properties[] = array(
                    "type"=> "args",
                    "info" => array($args, $this->scanner->getPosition())
                );
            } else {
                break;
            }
        }
        
        $propCount = count($properties);
        
        if (!$valid) {
            return $this->error();
        } elseif (!$propCount && !$newTokensCount) {
            return $object;
        }
        
        $node = null;
        $endPos = $object->getLocation()->getEnd();
        foreach ($properties as $i => $property) {
            $lastNode = $node ? $node : $object;
            if ($property["type"] === "args") {
                if ($newTokensCount) {
                    $node = $this->createNode(
                        "NewExpression", array_pop($newTokens)
                    );
                    $newTokensCount--;
                } else {
                    $node = $this->createNode("CallExpression", $lastNode);
                }
                $node->setCallee($lastNode);
                $node->setArguments($property["info"][0]);
                $endPos = $property["info"][1];
            } elseif ($property["type"] === "id") {
                $node = $this->createNode("MemberExpression", $lastNode);
                $node->setObject($lastNode);
                $node->setProperty($property["info"]);
                $endPos = $property["info"]->getLocation()->getEnd();
            } elseif ($property["type"] === "computed") {
                $node = $this->createNode("MemberExpression", $lastNode);
                $node->setObject($lastNode);
                $node->setProperty($property["info"][0]);
                $node->setComputed(true);
                $endPos = $property["info"][1];
            } elseif ($property["type"] === "template") {
                $node = $this->createNode("TaggedTemplateExpression", $object);
                $node->setTag($lastNode);
                $node->setQuasi($property["info"]);
                $endPos = $property["info"]->getLocation()->getEnd();
            }
            $node = $this->completeNode($node, $endPos);
        }
        
        //Wrap the result in multiple NewExpression if there are "new" tokens
        if ($newTokensCount) {
            for ($i = $newTokensCount - 1; $i >= 0; $i--) {
                $lastNode = $node ? $node : $object;
                $node = $this->createNode("NewExpression", $newTokens[$i]);
                $node->setCallee($lastNode);
                $node = $this->completeNode($node);
            }
        }
        
        return $node;
    }
    
    /**
     * Parses a spread element
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\SpreadElement|null
     */
    protected function parseSpreadElement($yield = false)
    {
        if ($token = $this->scanner->consume("...")) {
            
            if ($argument = $this->parseAssignmentExpression(true, $yield)) {
                $node = $this->createNode("SpreadElement", $token);
                $node->setArgument($argument);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses an array literal
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\ArrayExpression|null
     */
    protected function parseArrayLiteral($yield = false)
    {
        if ($token = $this->scanner->consume("[")) {
            
            $elements = array();
            while (true) {
                if ($elision = $this->parseElision()) {
                    $elements = array_merge(
                        $elements, array_fill(0, $elision, null)
                    );
                }
                if (($element = $this->parseSpreadElement($yield)) ||
                    ($element = $this->parseAssignmentExpression(true, $yield))
                ) {
                    $elements[] = $element;
                    if (!$this->scanner->consume(",")) {
                        break;
                    }
                } else {
                    break;
                }
            }
            
            if ($this->scanner->consume("]")) {
                $node = $this->createNode("ArrayExpression", $token);
                $node->setElements($elements);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses an arguments list wrapped in curly brackets
     * 
     * @param bool $yield Yield mode
     * 
     * @return array|null
     */
    protected function parseArguments($yield = false)
    {
        if ($this->scanner->consume("(")) {
            
            if (($args = $this->parseArgumentList($yield)) !== null &&
                $this->scanner->consume(")")
            ) {
                return $args;
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses an arguments list
     * 
     * @param bool $yield Yield mode
     * 
     * @return array|null
     */
    protected function parseArgumentList($yield = false)
    {
        $list = array();
        $start = $valid = true;
        while (true) {
            $spread = $this->scanner->consume("...");
            $exp = $this->parseAssignmentExpression(true, $yield);
            if (!$exp) {
                $valid = $valid && $start && !$spread;
                break;
            }
            if ($spread) {
                $node = $this->createNode("SpreadElement", $spread);
                $node->setArgument($exp);
                $list[] = $this->completeNode($node);
            } else {
                $list[] = $exp;
            }
            $valid = true;
            if (!$this->scanner->consume(",")) {
                break;
            } else {
                $valid = false;
            }
        }
        $start = false;
        if (!$valid) {
            return $this->error();
        }
        return $list;
    }
    
    /**
     * Parses a super call or a super property
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\Node|null
     */
    protected function parseSuperPropertyOrCall($yield = false)
    {
        if ($token = $this->scanner->consume("super")) {
            
            $super = $this->completeNode($this->createNode("Super", $token));
            
            if (($args = $this->parseArguments($yield)) !== null) {
                $node = $this->createNode("CallExpression", $token);
                $node->setArguments($args);
                $node->setCallee($super);
                return $this->completeNode($node);
            }
            
            $node = $this->createNode("MemberExpression", $token);
            $node->setObject($super);
            
            if ($this->scanner->consume(".")) {
                
                if ($property = $this->parseIdentifier()) {
                    $node->setProperty($property);
                    return $this->completeNode($node);
                }
            } elseif ($this->scanner->consume("[") &&
                ($property = $this->parseExpression(true, $yield)) &&
                $this->scanner->consume("]")
            ) {
                
                $node->setProperty($property);
                $node->setComputed(true);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses a primary expression
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\Node|null
     */
    protected function parsePrimaryExpression($yield = false)
    {
        if ($token = $this->scanner->consume("this")) {
            $node = $this->createNode("ThisExpression", $token);
            return $this->completeNode($node);
        } elseif ($exp = $this->parseIdentifier($yield)) {
            return $exp;
        } elseif ($exp = $this->parseLiteral()) {
            return $exp;
        } elseif ($exp = $this->parseArrayLiteral($yield)) {
            return $exp;
        } elseif ($exp = $this->parseObjectLiteral($yield)) {
            return $exp;
        } elseif ($exp = $this->parseFunctionOrGeneratorExpression()) {
            return $exp;
        } elseif ($exp = $this->parseClassExpression($yield)) {
            return $exp;
        } elseif ($exp = $this->parseRegularExpressionLiteral()) {
            return $exp;
        } elseif ($exp = $this->parseTemplateLiteral($yield)) {
            return $exp;
        } elseif ($token = $this->scanner->consume("(")) {
            
            if (($exp = $this->parseExpression(true, $yield)) &&
                $this->scanner->consume(")")
            ) {
                
                $node = $this->createNode("ParenthesizedExpression", $token);
                $node->setExpression($exp);
                return $this->completeNode($node);
            }
            
            return $this->error();
        }
        return null;
    }
    
    /**
     * Parses an identifier
     * 
     * @param bool   $disallowYield If this parameter is null every keyword
     *                              will be parsed as an identifier, if it's
     *                              false only yield keyword will be parsed as
     *                              identifier and if it's true keywords are
     *                              never considered as indentifiers
     * @param string $after         If a string is passed in this parameter, the
     *                              identifier is parsed only if preceeds this
     *                              string
     * 
     * @return Node\Identifier|null
     */
    protected function parseIdentifier($disallowYield = null, $after = null)
    {
        $token = $this->scanner->getToken();
        if (!$token) {
            return null;
        }
        if ($after !== null) {
            $next = $this->scanner->getNextToken();
            if (!$next || $next->getValue() !== $after) {
                return null;
            }
        }
        $type = $token->getType();
        switch ($type) {
            case Token::TYPE_BOOLEAN_LITERAL:
            case Token::TYPE_NULL_LITERAL:
            case Token::TYPE_KEYWORD:
                if ($disallowYield !== null && (
                        $type !== Token::TYPE_KEYWORD ||
                        $this->scanner->isStrictModeKeyword($token)
                    ) && (
                        $disallowYield ||
                        $token->getValue() !== "yield" ||
                        $this->scanner->getStrictMode()
                    )
                ) {
                    return null;
                }
            break;
            default:
                if ($type !== Token::TYPE_IDENTIFIER) {
                    return null;
                }
            break;
        }
        $this->scanner->consumeToken();
        $node = $this->createNode("Identifier", $token);
        $node->setName($token->getValue());
        return $this->completeNode($node);
    }
    
    /**
     * Parses a literal
     * 
     * @return Node\Literal|null
     */
    protected function parseLiteral()
    {
        $token = $this->scanner->getToken();
        if ($token && ($token->getType() === Token::TYPE_NULL_LITERAL ||
            $token->getType() === Token::TYPE_BOOLEAN_LITERAL)
        ) {
            $this->scanner->consumeToken();
            $node = $this->createNode("Literal", $token);
            $node->setRaw($token->getValue());
            return $this->completeNode($node);
        } elseif ($literal = $this->parseStringLiteral()) {
            return $literal;
        } elseif ($literal = $this->parseNumericLiteral()) {
            return $literal;
        }
        return null;
    }
    
    /**
     * Parses a string literal
     * 
     * @return Node\Literal|null
     */
    protected function parseStringLiteral()
    {
        $token = $this->scanner->getToken();
        if ($token && $token->getType() === Token::TYPE_STRING_LITERAL) {
            $this->scanner->consumeToken();
            $node = $this->createNode("Literal", $token);
            $node->setRaw($token->getValue());
            return $this->completeNode($node);
        }
        return null;
    }
    
    /**
     * Parses a numeric literal
     * 
     * @return Node\Literal|null
     */
    protected function parseNumericLiteral()
    {
        $token = $this->scanner->getToken();
        if ($token && $token->getType() === Token::TYPE_NUMERIC_LITERAL) {
            $this->scanner->consumeToken();
            $node = $this->createNode("Literal", $token);
            $node->setRaw($token->getValue());
            return $this->completeNode($node);
        }
        return null;
    }
    
    /**
     * Parses a template literal
     * 
     * @param bool $yield Yield mode
     * 
     * @return Node\Literal|null
     */
    protected function parseTemplateLiteral($yield = false)
    {
        $token = $this->scanner->getToken();
        
        if (!$token || $token->getType() !== Token::TYPE_TEMPLATE) {
            return null;
        }
        
        //Do not parse templates parts
        $val = $token->getValue();
        if ($val[0] !== "`") {
            return null;
        }
        
        $quasis = $expressions = array();
        $valid = false;
        do {
            $this->scanner->consumeToken();
            $val = $token->getValue();
            $lastChar = substr($val, -1);
            
            $quasi = $this->createNode("TemplateElement", $token);
            $quasi->setRawValue($val);
            if ($lastChar === "`") {
                $quasi->setTail(true);
                $quasis[] = $this->completeNode($quasi);
                $valid = true;
                break;
            } else {
                $quasis[] = $this->completeNode($quasi);
                if ($exp = $this->parseExpression(true, $yield)) {
                    $expressions[] = $exp;
                } else {
                    $valid = false;
                    break;
                }
            }
            
            $token = $this->scanner->getToken();
        } while ($token && $token->getType() === Token::TYPE_TEMPLATE);
        
        if ($valid) {
            $node = $this->createNode("TemplateLiteral", $quasis[0]);
            $node->setQuasis($quasis);
            $node->setExpressions($expressions);
            return $this->completeNode($node);
        }
        
        return $this->error();
    }
    
    /**
     * Parses a regular expression literal
     * 
     * @return Node\Literal|null
     */
    protected function parseRegularExpressionLiteral()
    {
        if ($token = $this->scanner->reconsumeCurrentTokenAsRegexp()) {
            $this->scanner->consumeToken();
            $node = $this->createNode("RegExpLiteral", $token);
            $node->setRaw($token->getValue());
            return $this->completeNode($node);
        }
        return null;
    }
    
    /**
     * Parse directive prologues. The result is an array where the first element
     * is the array of parsed nodes and the second element is the array of
     * directive prologues values
     * 
     * @return array|null
     */
    protected function parseDirectivePrologues()
    {
        $directives = array();
        $nodes = array();
        while ($directive = $this->parseStringLiteral()) {
            $this->assertEndOfStatement();
            $directives[] = $directive->getValue();
            $node = $this->createNode("ExpressionStatement", $directive);
            $node->setExpression($directive);
            $nodes[] = $this->completeNode($node);
        }
        return count($nodes) ? array($nodes, $directives) : null;
    }
}