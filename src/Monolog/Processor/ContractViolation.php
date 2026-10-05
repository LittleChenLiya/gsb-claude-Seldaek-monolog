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

/**
 * Describes why a contract processor chain run was rejected.
 *
 * @author Monolog contributors
 */
final class ContractViolation
{
    /** A processor changed a LogRecord path its contract did not allow */
    public const REASON_OUT_OF_CONTRACT_MODIFICATION = 'out_of_contract_modification';

    /** A value in the record cannot be deep-verified and has no registered verifier */
    public const REASON_NON_VERIFIABLE_VALUE = 'non_verifiable_value';

    public function __construct(
        public readonly string $reason,
        /** Dot-notated path of the offending value, e.g. "context" or "extra.user.name" */
        public readonly string $path,
        /** Index of the processor in chain execution order (0 = first executed) */
        public readonly int $processorIndex,
    ) {
    }
}
