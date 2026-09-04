<?php

namespace Tests\Validator\User;

use Nails\Auth\Validator\User\Identifier;
use Nails\Common\Exception\ValidationException;
use Nails\Config;
use PHPUnit\Framework\TestCase;

class IdentifierTest extends TestCase
{
    private function errorsFor(array $aData): array
    {
        try {
            (new Identifier())->run($aData);
            return [];
        } catch (ValidationException $e) {
            return $e->getData();
        }
    }

    public function test_the_identifier_must_be_an_email_when_logging_in_by_email(): void
    {
        Config::set('APP_NATIVE_LOGIN_USING', 'EMAIL');

        self::assertSame(['identifier' => 'This must be a valid email.'], $this->errorsFor(['identifier' => 'ada']));
        self::assertSame([], $this->errorsFor(['identifier' => 'ada@example.com']));
    }

    public function test_any_value_will_do_otherwise(): void
    {
        Config::set('APP_NATIVE_LOGIN_USING', 'BOTH');

        self::assertSame(['identifier' => 'This field is required.'], $this->errorsFor(['identifier' => '']));
        self::assertSame([], $this->errorsFor(['identifier' => 'ada']));
    }
}
