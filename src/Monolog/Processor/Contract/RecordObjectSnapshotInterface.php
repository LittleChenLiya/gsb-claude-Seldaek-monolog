<?php declare(strict_types=1);

/*
 * This file is part of the Monolog package.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Monolog\Processor\Contract;

/**
 * Implemented by objects that can provide a scalar/array snapshot of their state so
 * that a processor contract chain can verify them without calling arbitrary getters.
 *
 * The snapshot must fully represent the state that a processor may modify, and must be
 * returned as a scalar or array (nested arrays are supported) so the chain can diff
 * it deeply.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
interface RecordObjectSnapshotInterface
{
    /**
     * Returns a scalar or array snapshot of the object's current state.
     *
     * @return scalar|array<mixed>
     */
    public function snapshotForRecordContract(): mixed;
}
