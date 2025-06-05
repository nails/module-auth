<?php

namespace Nails\Auth\Auth\PasswordEngine;

use Nails\Auth\Interfaces\PasswordEngine;

/**
 * Class Sha1
 *
 * @package Nails\Auth\Auth\PasswordEngine
 */
class Sha1 implements PasswordEngine
{
    /**
     * @inheritDoc
     */
    public function hash(?string $sPassword, ?string $sSalt): string
    {
        return sha1(sha1($sPassword ?? '') . $sSalt);
    }
}
