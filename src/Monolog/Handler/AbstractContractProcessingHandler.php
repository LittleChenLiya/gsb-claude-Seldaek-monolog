<?php declare(strict_types=1);

/*
 * This file is part of the Monolog package.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Monolog\Handler;

use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Processor\ContractProcessorChain;
use Monolog\Processor\ContractViolation;
use Monolog\Processor\ProcessorContract;

/**
 * Base handler running its processors through an explicit ContractProcessorChain.
 *
 * Each processor declares the LogRecord paths it may modify and runs on an
 * independent candidate record. When a processor changes anything outside its
 * contract, or the record holds a value that cannot be deep-verified, the write
 * is blocked entirely: the handler never emits a half-processed record and the
 * original record stays untouched.
 *
 * The default processor mechanism (ProcessableHandlerTrait) is not affected by
 * this class; handlers opt into contract processing by extending it.
 *
 * @author Monolog contributors
 */
abstract class AbstractContractProcessingHandler extends AbstractHandler implements FormattableHandlerInterface
{
    use FormattableHandlerTrait;

    private ContractProcessorChain $chain;

    private ?ContractViolation $lastViolation = null;

    /**
     * @param int|string|Level $level  The minimum logging level at which this handler will be triggered
     * @param bool             $bubble Whether the messages that are handled can bubble up the stack or not
     */
    public function __construct(int|string|Level $level = Level::Debug, bool $bubble = true)
    {
        parent::__construct($level, $bubble);

        $this->chain = new ContractProcessorChain();
    }

    /**
     * Pushes a contracted processor. As with the default processor stack, the
     * last pushed processor runs first.
     *
     * @param callable(LogRecord): LogRecord $processor
     *
     * @return $this
     */
    public function pushContractProcessor(callable $processor, ?ProcessorContract $contract = null): self
    {
        $this->chain->push($processor, $contract);

        return $this;
    }

    /**
     * Registers a verifier for a custom value type found in records, see
     * ContractProcessorChain::registerVerifier().
     *
     * @param class-string            $class
     * @param callable(object): mixed $snapshotter
     *
     * @return $this
     */
    public function registerValueVerifier(string $class, callable $snapshotter): self
    {
        $this->chain->registerVerifier($class, $snapshotter);

        return $this;
    }

    /**
     * The violation that blocked the last handle() call, if any.
     */
    public function getLastViolation(): ?ContractViolation
    {
        return $this->lastViolation;
    }

    /**
     * @inheritDoc
     */
    public function handle(LogRecord $record): bool
    {
        if (!$this->isHandling($record)) {
            return false;
        }

        $this->lastViolation = null;

        if (\count($this->chain) > 0) {
            $result = $this->chain->process($record);
            if (!$result->isOk()) {
                // contract violated: block the write so no partial log is emitted
                $this->lastViolation = $result->getViolation();

                return false;
            }
            $record = $result->getRecord();
        }

        $record->formatted = $this->getFormatter()->format($record);

        $this->write($record);

        return !$this->getBubble();
    }

    /**
     * Writes the (already processed and formatted) record down to the log of the implementing handler
     */
    abstract protected function write(LogRecord $record): void;
}
