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
 * Explicit contract declaring which LogRecord paths a processor is allowed to modify.
 *
 * Paths are dot-notated, e.g. "extra", "extra.uid", "message", "context.user".
 * Allowing a path also allows everything below it, so "extra" covers "extra.uid".
 *
 * @author Monolog contributors
 */
final class ProcessorContract
{
    /** @var list<string> */
    private const FIELDS = ['datetime', 'channel', 'level', 'message', 'context', 'extra', 'formatted'];

    /** @var list<string> */
    private array $allowedPaths;

    /**
     * @param list<string> $allowedPaths
     */
    private function __construct(array $allowedPaths)
    {
        $normalized = [];
        foreach ($allowedPaths as $path) {
            $path = trim($path, ". \t\n\r\0\x0B");
            if ($path === '') {
                throw new \InvalidArgumentException('Contract paths must not be empty');
            }
            $topLevel = explode('.', $path, 2)[0];
            if (!\in_array($topLevel, self::FIELDS, true)) {
                throw new \InvalidArgumentException('Unknown LogRecord path "'.$path.'", top level must be one of: '.implode(', ', self::FIELDS));
            }
            $normalized[] = $path;
        }

        $this->allowedPaths = array_values(array_unique($normalized));
    }

    /**
     * A contract allowing no modification at all (read-only processor).
     */
    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @param string ...$paths dot-notated LogRecord paths the processor may modify
     */
    public static function allowing(string ...$paths): self
    {
        return new self(array_values($paths));
    }

    /**
     * Checks whether the given dot-notated path is covered by this contract.
     */
    public function allows(string $path): bool
    {
        foreach ($this->allowedPaths as $allowed) {
            if ($path === $allowed || str_starts_with($path, $allowed.'.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function getAllowedPaths(): array
    {
        return $this->allowedPaths;
    }
}
