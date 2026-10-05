<?php declare(strict_types=1);

/*
 * This file is part of the Monolog package.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Monolog\Processor;

use Monolog\LogRecord;

/**
 * Explicit contract processor chain.
 *
 * Every processor is registered together with a ProcessorContract declaring the
 * LogRecord paths it may modify. Each processor runs on an independent candidate
 * record (deep-isolated arrays, cloned mutable values) and its result is
 * deep-verified against the contract before being passed on:
 *
 *  - a change outside the declared paths rejects the whole chain: the original
 *    record is returned untouched and no half-processed record is produced
 *  - a record containing an unknown mutable object without a registered verifier
 *    is non-verifiable: the chain aborts before the next callback is invoked
 *
 * Processors run in the same order and with the same callback counts as the
 * default Monolog processor stack: pushed processors are unshifted, so the last
 * pushed processor runs first, and each callback runs exactly once per record
 * unless the chain aborts earlier.
 *
 * @author Monolog contributors
 */
final class ContractProcessorChain implements \Countable
{
    /** @var list<ContractedProcessor> */
    private array $processors = [];

    private ContractVerifier $verifier;

    public function __construct()
    {
        $this->verifier = new ContractVerifier();
    }

    /**
     * Pushes a processor onto the chain. Like ProcessableHandlerTrait::pushProcessor,
     * the last pushed processor is executed first.
     *
     * @param callable(LogRecord): LogRecord $processor
     */
    public function push(callable $processor, ?ProcessorContract $contract = null): self
    {
        if (null === $contract && $processor instanceof ContractedProcessor) {
            $contract = $processor->getContract();
        }
        if (null === $contract) {
            throw new \InvalidArgumentException('A ProcessorContract is required for every processor in the contract chain');
        }

        array_unshift($this->processors, new ContractedProcessor($processor, $contract));

        return $this;
    }

    /**
     * Registers a verifier for a custom value type so records containing it can
     * be deep-verified. The callback must return a snapshot of the object using
     * scalars and/or arrays only.
     *
     * @param class-string            $class
     * @param callable(object): mixed $snapshotter
     */
    public function registerVerifier(string $class, callable $snapshotter): self
    {
        $this->verifier->registerVerifier($class, $snapshotter);

        return $this;
    }

    public function count(): int
    {
        return \count($this->processors);
    }

    /**
     * Runs the chain over the given record.
     *
     * On success the processed record is returned via ContractChainResult::getRecord().
     * On any violation the chain aborts immediately, remaining callbacks are not
     * invoked and the result holds the untouched original record.
     */
    public function process(LogRecord $record): ContractChainResult
    {
        $current = $record;

        foreach ($this->processors as $index => $processor) {
            if (null !== $path = $this->verifier->findNonVerifiable($current)) {
                return ContractChainResult::violated($record, new ContractViolation(
                    ContractViolation::REASON_NON_VERIFIABLE_VALUE,
                    $path,
                    $index,
                ));
            }

            $candidate = $this->verifier->isolate($current);
            $snapshot = $this->verifier->snapshot($candidate);

            $result = $processor($candidate);
            if (!$result instanceof LogRecord) {
                throw new \UnexpectedValueException('Contract processors must return a Monolog\LogRecord instance');
            }

            if (null !== $path = $this->verifier->verify($snapshot, $result, $processor->getContract())) {
                return ContractChainResult::violated($record, new ContractViolation(
                    ContractViolation::REASON_OUT_OF_CONTRACT_MODIFICATION,
                    $path,
                    $index,
                ));
            }

            $current = $result;
        }

        return ContractChainResult::ok($current);
    }
}
