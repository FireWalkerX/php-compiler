<?php

use PhpParser\ParserFactory;

$rawCode = <<<'EOF'
$i = 0;
while ($i < 100) {
    $i++;
    $message = "test $i \n";
    echo $message . "another\n";
}
EOF;
$code = '<?php ' . $rawCode;

require 'vendor/autoload.php';

$times = [];

$times['start'] = microtime(true);
$astTraverser = new PhpParser\NodeTraverser;
$astTraverser->addVisitor(new PhpParser\NodeVisitor\NameResolver);
$parser = new PHPCfg\Parser((new ParserFactory)->create(ParserFactory::ONLY_PHP7), $astTraverser);

$traverser = new PHPCfg\Traverser;
$traverser->addVisitor(new PHPCfg\Visitor\Simplifier);

$typeReconstructor = new PHPTypes\TypeReconstructor;
$dumper = new PHPCfg\Printer\Text();
$optimizer = new PHPOptimizer\Optimizer;
$compiler = new PHPCompiler\Backend\VM\Compiler;

$times['Initialize Libraries'] = microtime(true);

$block = $parser->parse($code, __FILE__);
$times['Parse'] = microtime(true);

$traverser->traverse($block);
$times['Traverse CFG'] = microtime(true);

$state = new PHPTypes\State([$block]);
$typeReconstructor->resolve($state);
$times['Reconstruct Types'] = microtime(true);

$blocks = $state->blocks;

//$blocks = $optimizer->optimize($blocks);

$opcodes = $compiler->compile($blocks);
$times['Compile'] = microtime(true);





echo $dumper->printCFG($blocks);
$times['Dump CFG'] = microtime(true);

echo "\n\nEval Output:\n\n";
eval($rawCode);
$times['Eval Code'] = microtime(true);

echo "\n\nVM Output\n\n";
PHPCompiler\Backend\VM\VM::run($opcodes, new PHPCompiler\Backend\VM\Context
);
$times['Run in VM'] = microtime(true);

echo "\n\nTimers:\n";
$start = array_shift($times);
foreach ($times as $key => $time) {
    echo "  $key: " . ($time - $start) . "\n";
    $start = $time;
}
