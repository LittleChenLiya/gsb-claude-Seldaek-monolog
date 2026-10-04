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

/**
 * Thrown when a spool segment contains damaged data that can not be recovered.
 *
 * The reported position (segment index + byte offset within that segment) is the
 * start of the first frame that failed validation. Delivery always stops before
 * that position so that no record is silently skipped.
 */
class SpoolCorruptionException extends SpoolException
{
    public function __construct(
        string $message,
        private readonly int $segmentIndex,
        private readonly int $offset,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('%s at segment %d, offset %d', $message, $segmentIndex, $offset),
            0,
            $previous,
        );
    }

    /**
     * Index of the damaged segment file.
     */
    public function getSegmentIndex(): int
    {
        return $this->segmentIndex;
    }

    /**
     * Byte offset of the damaged frame inside the segment file.
     */
    public function getOffset(): int
    {
        return $this->offset;
    }
}
