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
 * Outcome of running a ContractProcessorChain.
 *
 * On violation the original, untouched record is kept so no half-processed
 * record can leak downstream.
 *
 * @author Monolog contributors
 */
final class ContractChainResult
{
    private function __construct(
        private LogRecord $record,
        private ?ContractViolation $violation,
    ) {
    }

    public static function ok(LogRecord $record): self
    {
        return new self($record, null);
    }

    public static function violated(LogRecord $originalRecord, ContractViolation $violation): self
    {
        return new self($originalRecord, $violation);
    }

    public function isOk(): bool
    {
        return $this->violation === null;
    }

    /**
     * The processed record when ok, the untouched original record when violated.
     */
    public function getRecord(): LogRecord
    {
        return $this->record;
    }

    public function getViolation(): ?ContractViolation
    {
        return $this->violation;
    }
}
