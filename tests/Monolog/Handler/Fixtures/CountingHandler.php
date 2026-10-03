<?php declare(strict_types=1);

/*
 * This file is part of the Monolog package.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Monolog\Handler\Fixtures;

use Monolog\Handler\TestHandler;
use Monolog\LogRecord;

/**
 * Test handler that counts how many times close() and reset() are called.
 *
 * It records every received record just like TestHandler, and can optionally
 * be configured to throw while handling a record so that routing behavior in
 * case of a failing target can be exercised.
 */
class CountingHandler extends TestHandler
{
    public int $closeCount = 0;
    public int $resetCount = 0;
    public bool $throwOnHandle = false;

    /**
     * @inheritDoc
     */
    public function close(): void
    {
        $this->closeCount++;
        parent::close();
    }

    /**
     * @inheritDoc
     */
    public function reset(): void
    {
        $this->resetCount++;
        parent::reset();
    }

    /**
     * @inheritDoc
     */
    protected function write(LogRecord $record): void
    {
        if ($this->throwOnHandle) {
            throw new \RuntimeException('CountingHandler was configured to throw while handling a record.');
        }

        parent::write($record);
    }
}
