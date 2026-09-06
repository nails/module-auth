<?php

namespace Tests\Auth\Stub;

use Nails\Common\Service\Logger;

/**
 * A stand-in for the logger which records what it was asked to write, so that
 * what a job reports can be asserted without touching the filesystem.
 *
 * The real service reaches for the Logger factory in its constructor, so the
 * constructor is replaced outright rather than mocked - the same bargain
 * CdnSpy makes.
 */
class LoggerSpy extends Logger
{
    /** @var string[] */
    public array $aInfo = [];

    /** @var string[] */
    public array $aWarnings = [];

    /** @var string[] */
    public array $aErrors = [];

    // --------------------------------------------------------------------------

    public function __construct()
    {
        //  Deliberately does not open a stream
    }

    // --------------------------------------------------------------------------

    public function info($sLine = ''): self
    {
        $this->aInfo[] = (string) $sLine;
        return $this;
    }

    // --------------------------------------------------------------------------

    public function warning($sLine = ''): self
    {
        $this->aWarnings[] = (string) $sLine;
        return $this;
    }

    // --------------------------------------------------------------------------

    public function error($sLine = ''): self
    {
        $this->aErrors[] = (string) $sLine;
        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Every line recorded, whatever the level
     *
     * @return string[]
     */
    public function all(): array
    {
        return array_merge($this->aInfo, $this->aWarnings, $this->aErrors);
    }
}
