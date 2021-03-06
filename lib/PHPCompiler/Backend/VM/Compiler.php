<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\Backend\VM;

use PHPCfg\Op;
use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;
use PHPTypes\Type;

class Compiler {

    protected ?\SplObjectStorage $seen;

    public function compile(array $blocks): ?Block {
        $this->seen = new \SplObjectStorage;
        $firstBlock = null;
        foreach ($blocks as $block) {
            $result = $this->compileCfgBlock($block);
            if (is_null($firstBlock)) {
                $firstBlock = $result;
            }
        }
        $this->seen = null;
        return $firstBlock;
    }

    protected function compileCfgBlock(CfgBlock $block): Block {
        if (!$this->seen->contains($block)) {
            $this->seen[$block] = $new = new Block($block);
            $this->compileBlock($new);
        }
        return $this->seen[$block];
    }

    protected function compileBlock(Block $block) {
        foreach ($block->orig->children as $child) {
            switch (get_class($child)) {
                case Op\Stmt\Function_::class:
                    $block->addOpCode(...$this->compileFunction($child));
                    break;
                case Op\Stmt\Class_::class:
                case Op\Stmt\Interface_::class:
                case Op\Stmt\Trait_::class:
                    $block->addOpCode(...$this->compileClassLike($child));
                    break;
                default:
                    $this->compileOp($child, $block);
            }
        }
    }

    protected function compileFunction(Op\Stmt\Function_ $func) {

        var_dump($func);
        die();
    }

    protected function compileClass(Op\Stmt\Class_ $class) {
        var_dump($class);
        die();
    }

    protected function compileOp(Op $op, Block $block) {
        if ($op instanceof Op\Expr\ConcatList) {
            // special case, since it's a multi-op expression
            $total = count($op->list);
            assert($total > 0);
            $pointer = 1;
            $result = $this->compileOperand($op->list[0], $block);
            while ($pointer < $total) {
                $right = $this->compileOperand($op->list[$pointer++], $block);
                $tmpResult = $this->compileOperand(new Operand\Temporary, $block);
                $block->addOpCode(new OpCode(
                    OpCode::TYPE_CONCAT,
                    $tmpResult,
                    $result,
                    $right
                ));
                $result = $tmpResult;
            }
            $return = $this->compileOperand($op->result, $block);
            $block->addOpCode(new OpCode(
                OpCode::TYPE_ASSIGN,
                $return,
                $return,
                $result
            ));
        } elseif ($op instanceof Op\Expr) {
            $block->addOpCode($this->compileExpr($op, $block));
        } elseif ($op instanceof Op\Stmt) {
            $this->compileStmt($op, $block);
        } elseif ($op instanceof Op\Terminal) {
            $block->addOpCode($this->compileTerminal($op, $block));
        } else {
            throw new \LogicException("Unknown Op Type: " . $op->getType());
        }
    }

    protected function compileStmt(Op\Stmt $stmt, Block $block) {
        if ($stmt instanceof Op\Stmt\Jump) {
            $op = new OpCode(OpCode::TYPE_JUMP);
            $op->block1 = $this->compileCfgBlock($stmt->target);
            $block->addOpCode($op);
        } elseif ($stmt instanceof Op\Stmt\JumpIf) {
            $op = new OpCode(OpCode::TYPE_JUMPIF, $this->compileOperand($stmt->cond, $block));
            $op->block1 = $this->compileCfgBlock($stmt->if);
            $op->block2 = $this->compileCfgBlock($stmt->else);
            $block->addOpCode($op);
        } else {
            throw new \LogicException("Unknown Stmt Type: " . $stmt->getType());
        }
    }

    protected function getOpCodeTypeFromBinaryOp(Op\Expr\BinaryOp $expr): int {
        if ($expr instanceof Op\Expr\BinaryOp\Concat) {
            return OpCode::TYPE_CONCAT;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Plus) {
            return OpCode::TYPE_PLUS;
        } elseif ($expr instanceof Op\Expr\BinaryOp\Smaller) {
            return OpCode::TYPE_SMALLER;
        }
        throw new \LogicException("Unknown BinaryOp Type: " . $expr->getType());
    }

    protected function compileExpr(Op\Expr $expr, Block $block): OpCode {
        if ($expr instanceof Op\Expr\BinaryOp) {
            return new OpCode(
                $this->getOpCodeTypeFromBinaryOp($expr),
                $this->compileOperand($expr->result, $block),
                $this->compileOperand($expr->left, $block),
                $this->compileOperand($expr->right, $block),
            );
        }
        switch (get_class($expr)) {
            case Op\Expr\Assign::class:
                return new OpCode(
                    OpCode::TYPE_ASSIGN,
                    $this->compileOperand($expr->result, $block),   
                    $this->compileOperand($expr->var, $block),
                    $this->compileOperand($expr->expr, $block) 
                );
            case Op\Expr\ConstFetch::class:
                $nsName = null;
                if (!is_null($expr->nsName)) {
                    $nsName = $this->compileOperand($expr->nsName, $block);
                }
                return new OpCode(
                    OpCode::TYPE_CONST_FETCH,
                    $this->compileOperand($expr->result, $block),
                    $this->compileOperand($expr->name, $block),
                    $nsName
                );
        }
        throw new \LogicException("Unsupported expression: " . $expr->getType());
    }

    protected function compileOperand(Operand $operand, Block $block): int {
        if ($operand instanceof Operand\Literal) {
            $return = new PHPVar($operand->type->type);
            switch ($operand->type->type) {
                case Type::TYPE_STRING:
                    $return->string = Str::fromPrimitive($operand->value);
                    break;
                case Type::TYPE_LONG:
                    $return->integer = $operand->value;
                    break;
                default:
                    throw new \LogicException("Unknown Literal Operand Type: " . $operand->type);
            }
            return $block->registerConstant($operand, $return);
        } elseif ($operand instanceof Operand\Temporary) {
            return $block->getVarSlot($operand);
        }
        throw new \LogicException("Unknown Operand Type: " . $operand->getType());
    }

    protected function compileTerminal(Op\Terminal $terminal, Block $block): OpCode {
        switch ($terminal->getType()) {
            case 'Terminal_Echo':
                $var = $this->compileOperand($terminal->expr, $block);
                return new OpCode(
                    OpCode::TYPE_ECHO,
                    $var
                );
            default:
                throw new \LogicException("Unknown Terminal Type: " . $terminal->getType());
        }
    }

}