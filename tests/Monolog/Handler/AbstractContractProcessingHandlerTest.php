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

use Monolog\LogRecord;
use Monolog\Processor\ContractViolation;
use Monolog\Processor\ProcessorContract;
use Monolog\Test\MonologTestCase;

class ContractRecordingHandler extends AbstractContractProcessingHandler
{
    /** @var list<LogRecord> */
    public array $records = [];

    protected function write(LogRecord $record): void
    {
        $this->records[] = $record;
    }
}

class AbstractContractProcessingHandlerTest extends MonologTestCase
{
    public function testLegalModificationIsWritten(): void
    {
        $handler = new ContractRecordingHandler();
        $handler->pushContractProcessor(function (LogRecord $record): LogRecord {
            $record->extra['uid'] = 'abc123';

            return $record;
        }, ProcessorContract::allowing('extra'));

        $record = $this->getRecord();
        $handled = $handler->handle($record);

        $this->assertFalse($handled); // bubbles by default
        $this->assertCount(1, $handler->records);
        $this->assertSame('abc123', $handler->records[0]->extra['uid']);
        $this->assertNotNull($handler->records[0]->formatted);
        $this->assertNull($handler->getLastViolation());
        // the original record was not modified
        $this->assertSame([], $record->extra);
        $this->assertNull($record->formatted);
    }

    public function testOutOfContractModificationBlocksTheWrite(): void
    {
        $handler = new ContractRecordingHandler();
        $handler->pushContractProcessor(function (LogRecord $record): LogRecord {
            // contract allows extra only, but the processor changes context
            return $record->with(context: ['injected' => true]);
        }, ProcessorContract::allowing('extra'));

        $record = $this->getRecord(context: ['original' => 'context']);
        $handled = $handler->handle($record);

        // the write was blocked: no partial log was emitted
        $this->assertFalse($handled);
        $this->assertCount(0, $handler->records);
        $violation = $handler->getLastViolation();
        $this->assertNotNull($violation);
        $this->assertSame(ContractViolation::REASON_OUT_OF_CONTRACT_MODIFICATION, $violation->reason);
        $this->assertSame('context.injected', $violation->path);
        // the original record is unchanged
        $this->assertSame(['original' => 'context'], $record->context);
    }

    public function testNonVerifiableCustomObjectBlocksCallbackAndWrite(): void
    {
        $handler = new ContractRecordingHandler();
        $called = 0;
        $handler->pushContractProcessor(function (LogRecord $record) use (&$called): LogRecord {
            $called++;
            $record->extra['x'] = 1;

            return $record;
        }, ProcessorContract::allowing('extra'));

        $record = $this->getRecord(context: ['custom' => new \stdClass()]);
        $handled = $handler->handle($record);

        // no verification means for the custom object: callback count is 0
        $this->assertSame(0, $called);
        // and nothing was written
        $this->assertFalse($handled);
        $this->assertCount(0, $handler->records);
        $this->assertSame(ContractViolation::REASON_NON_VERIFIABLE_VALUE, $handler->getLastViolation()->reason);
    }

    public function testRegisteredVerifierLetsTheRecordThrough(): void
    {
        $handler = new ContractRecordingHandler();
        $handler->registerValueVerifier(\ArrayObject::class, fn (\ArrayObject $o): array => $o->getArrayCopy());
        $handler->pushContractProcessor(function (LogRecord $record): LogRecord {
            $record->extra['x'] = 1;

            return $record;
        }, ProcessorContract::allowing('extra'));

        $handled = $handler->handle($this->getRecord(context: ['bag' => new \ArrayObject(['a' => 1])]));

        $this->assertFalse($handled);
        $this->assertCount(1, $handler->records);
        $this->assertSame(1, $handler->records[0]->extra['x']);
    }

    public function testRecordBelowLevelIsNotHandled(): void
    {
        $handler = new ContractRecordingHandler(\Monolog\Level::Error);
        $handler->pushContractProcessor(function (LogRecord $record): LogRecord {
            $record->extra['x'] = 1;

            return $record;
        }, ProcessorContract::allowing('extra'));

        $this->assertFalse($handler->handle($this->getRecord(\Monolog\Level::Warning)));
        $this->assertCount(0, $handler->records);
    }
}
