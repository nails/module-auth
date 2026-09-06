<?php

namespace Tests\Auth\Stub;

use Nails\Auth\Model\User;
use Nails\Auth\Resource;
use RuntimeException;

/**
 * The user model with account creation recorded rather than performed.
 *
 * The real create() opens a transaction, writes across three tables, commits,
 * fires USER_CREATED and queues the welcome email; none of that is what the
 * per-row loop's hooks are about, and all of it needs a database.
 */
class UserModelStub extends User
{
    /**
     * Every call to create(), in order: ['data' => array, 'send_email' => bool]
     *
     * @var array<int, array{data: array, send_email: bool}>
     */
    public array $aCreated = [];

    /**
     * The ID to give the next account; incremented per call
     */
    public int $iNextId = 100;

    /**
     * What create() should do instead of succeeding: 'false' to report a
     * failure the way the real model does, 'throw' to fall over
     */
    public ?string $sFail = null;

    /**
     * The error create() should leave behind when it returns false
     */
    public ?string $sCreateError = 'The account could not be created';

    // --------------------------------------------------------------------------

    public function create(array $data = [], $bSendWelcome = true)
    {
        $this->aCreated[] = [
            'data'       => $data,
            'send_email' => (bool) $bSendWelcome,
        ];

        if ($this->sFail === 'throw') {
            throw new RuntimeException('the account could not be written');

        } elseif ($this->sFail === 'false') {

            if ($this->sCreateError !== null) {
                $this->setError($this->sCreateError);
            }

            return false;
        }

        return new Resource\User((object) [
            'id'    => $this->iNextId++,
            'email' => $data['email'] ?? null,
        ]);
    }
}
