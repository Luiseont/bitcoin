<?php

namespace BitWasp\Bitcoin\Script\Path;

use BitWasp\Bitcoin\Script\Interpreter\Stack;
use BitWasp\Bitcoin\Script\Opcodes;
use BitWasp\Bitcoin\Script\ScriptInterface;

class BranchInterpreter
{

    /**
     * @var array
     */
    private $disabledOps = [
        Opcodes::OP_CAT,    Opcodes::OP_SUBSTR, Opcodes::OP_LEFT,  Opcodes::OP_RIGHT,
        Opcodes::OP_INVERT, Opcodes::OP_AND,    Opcodes::OP_OR,    Opcodes::OP_XOR,
        Opcodes::OP_2MUL,   Opcodes::OP_2DIV,   Opcodes::OP_MUL,   Opcodes::OP_DIV,
        Opcodes::OP_MOD,    Opcodes::OP_LSHIFT, Opcodes::OP_RSHIFT
    ];

    /**
     * @param ScriptInterface $script
     * @return ParsedScript
     */
    public function getScriptTree(ScriptInterface $script)
    {
        $ast = $this->getAstForLogicalOps($script);
        $paths = $ast->flags();
        $results = [];

        if (count($paths) > 1) {
            foreach ($paths as $path) {
                $results[] = $this->getBranchForPath($script, $path);
            }
        } else {
            $results[] = $this->getBranchForPath($script, []);
        }

        return new ParsedScript($script, $ast, $results);
    }

    /**
     * Build tree of dependent logical ops
     * @param ScriptInterface $script
     * @return LogicOpNode
     */
    public function getAstForLogicalOps(ScriptInterface $script)
    {
        $root = new LogicOpNode(null);
        $current = $root;

        foreach ($script->getScriptParser()->decode() as $op) {
            switch ($op->getOp()) {
                case Opcodes::OP_IF:
                case Opcodes::OP_NOTIF:
                    $split = $current->split();
                    $current = $split[$op->getOp() & 1];
                    break;
                case Opcodes::OP_ENDIF:
                    if (null === $current->getParent()) {
                        throw new \RuntimeException("Unexpected ENDIF, current scope had no parent");
                    }
                    $current = $current->getParent();
                    break;
                case Opcodes::OP_ELSE:
                    if (null === $current->getParent()) {
                        throw new \RuntimeException("Unexpected ELSE, current scope had no parent");
                    }
                    $current = $current->getParent()->getChild(!$current->getValue());
                    break;
            }
        }

        return $root;
    }

    /**
     * Given a script and path, attempt to produce a ScriptBranch instance
     *
     * @param ScriptInterface $script
     * @param bool[] $path
     * @return ScriptBranch
     */
    public function getBranchForPath(ScriptInterface $script, array $path)
    {
        // parses the opcodes which were actually run
        $segments = $this->evaluateUsingStack($script, $path);

        return new ScriptBranch($script, $path, $segments);
    }

    /**
     * @param Stack $vfStack
     * @param bool $value
     * @return bool
     */
    private function checkExec(Stack $vfStack, $value)
    {
        $ret = 0;
        foreach ($vfStack as $item) {
            if ($item === $value) {
                $ret++;
            }
        }

        return $ret;
    }

    /**
     * @param ScriptInterface $script
     * @param int[] $path
     * @return array - array of Operation[] representing script segments
     */
    public function evaluateUsingStack(ScriptInterface $script, array $path)
    {
        $mainStack = new Stack();
        foreach (array_reverse($path) as $setting) {
            $mainStack->push($setting);
        }

        $vfStack = new Stack();
        $parser = $script->getScriptParser();
        $tracer = new PathTracer();

        foreach ($parser as $i => $operation) {
            $opCode = $operation->getOp();
            $fExec = !$this->checkExec($vfStack, false);

            if (in_array($opCode, $this->disabledOps, true)) {
                throw new \RuntimeException('Disabled Opcode');
            }

            if (Opcodes::OP_IF <= $opCode && $opCode <= Opcodes::OP_ENDIF) {
                switch ($opCode) {
                    case Opcodes::OP_IF:
                    case Opcodes::OP_NOTIF:
                        // <expression> if [statements] [else [statements]] endif
                        $value = false;
                        if ($fExec) {
                            if ($mainStack->isEmpty()) {
                                throw new \RuntimeException('Unbalanced conditional');
                            }

                            $value = $mainStack->pop();
                            if ($opCode === Opcodes::OP_NOTIF) {
                                $value = !$value;
                            }
                        }
                        $vfStack->push($value);
                        break;

                    case Opcodes::OP_ELSE:
                        if ($vfStack->isEmpty()) {
                            throw new \RuntimeException('Unbalanced conditional');
                        }
                        $vfStack->push(!$vfStack->pop());
                        break;

                    case Opcodes::OP_ENDIF:
                        if ($vfStack->isEmpty()) {
                            throw new \RuntimeException('Unbalanced conditional');
                        }
                        $vfStack->pop();

                        break;
                }

                $tracer->operation($operation);
            } else if ($fExec) {
                // Fill up trace with executed opcodes
                $tracer->operation($operation);
            }
        }

        if (count($vfStack) !== 0) {
            throw new \RuntimeException('Unbalanced conditional at script end');
        }

        if (count($mainStack) !== 0) {
            throw new \RuntimeException('Values remaining after script execution - invalid branch data');
        }

        return $tracer->done();
    }
}