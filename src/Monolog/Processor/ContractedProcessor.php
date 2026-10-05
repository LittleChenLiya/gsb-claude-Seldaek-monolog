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
 * Pairs a processor callable with the explicit ProcessorContract it must obey.
 *
 * @author Monolog contributors
 */
final class ContractedProcessor implements ProcessorInterface
{
    /** @var callable(LogRecord): LogRecord */
    private $callback;

    private ProcessorContract $contract;

    /**
     * @param callable(LogRecord): LogRecord $callback
     */
    public function __construct(callable $callback, ProcessorContract $contract)
    {
        $this->callback = $callback;
        $this->contract = $contract;
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        return ($this->callback)($record);
    }

    public function getContract(): ProcessorContract
    {
        return $this->contract;
    }
}
