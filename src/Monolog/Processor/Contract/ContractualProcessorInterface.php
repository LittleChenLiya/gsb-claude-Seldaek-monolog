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

use Monolog\LogRecord;

/**
 * A processor that explicitly declares which LogRecord paths it is allowed to modify.
 *
 * Paths are dot-separated field paths, e.g. "extra", "context.foo" or "extra.foo.bar".
 * A path covers itself and all of its descendants: allowing "extra" allows any change
 * under the extra field, while allowing "context.foo" only allows changes to that
 * specific key (and its descendants).
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
interface ContractualProcessorInterface
{
    /**
     * Returns the list of LogRecord paths this processor is allowed to modify.
     *
     * @return list<string>
     */
    public function allowedRecordPaths(): array;
}
