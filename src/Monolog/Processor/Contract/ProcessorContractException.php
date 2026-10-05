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
 * Thrown when a processor chain cannot verify a record, or when a processor modifies
 * a LogRecord path that is not covered by its declared contract.
 *
 * @author Nicolas Grekas <p@tchwork.com>
 */
class ProcessorContractException extends \RuntimeException
{
}
