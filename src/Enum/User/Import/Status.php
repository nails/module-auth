<?php

namespace Nails\Auth\Enum\User\Import;

enum Status: string
{
    /**
     * Uploaded, awaiting approval
     */
    case DRAFT = 'DRAFT';

    /**
     * Approved, awaiting a runner
     */
    case PENDING = 'PENDING';

    /**
     * The file is being validated; no users have been created
     */
    case VALIDATING = 'VALIDATING';

    /**
     * Users are being created
     */
    case RUNNING = 'RUNNING';

    /**
     * Finished, zero row errors
     */
    case COMPLETE = 'COMPLETE';

    /**
     * Finished, but some rows errored or warned
     */
    case PARTIAL = 'PARTIAL';

    /**
     * Rejected before, or while, running; no partial state
     */
    case FAILED = 'FAILED';

    // --------------------------------------------------------------------------

    /**
     * Whether the job has finished, one way or another
     */
    public function isTerminal(): bool
    {
        return in_array($this, static::terminal(), true);
    }

    // --------------------------------------------------------------------------

    /**
     * Whether the job has been approved and is not yet finished
     */
    public function isActive(): bool
    {
        return in_array($this, static::active(), true);
    }

    // --------------------------------------------------------------------------

    /**
     * Whether the job can be deleted
     *
     * Everything except the two statuses a runner holds mid-flight. PENDING is
     * deletable because no users exist yet, and the terminal statuses because
     * the job is over - note that deleting a finished job does not delete the
     * users it created; `user_import_item.user_id` is ON DELETE SET NULL for
     * exactly that reason.
     *
     * Deliberately not backed by a static array, as `active()` and `terminal()`
     * are: those exist because `values()` feeds `where_in()`, and nothing
     * queries by deletability.
     */
    public function isDeletable(): bool
    {
        return !in_array($this, [self::VALIDATING, self::RUNNING], true);
    }

    // --------------------------------------------------------------------------

    /**
     * The statuses which represent a finished job
     *
     * @return static[]
     */
    public static function terminal(): array
    {
        return [
            self::COMPLETE,
            self::PARTIAL,
            self::FAILED,
        ];
    }

    // --------------------------------------------------------------------------

    /**
     * The statuses a runner is permitted to pick a job up in
     *
     * @return static[]
     */
    public static function active(): array
    {
        return [
            self::PENDING,
            self::VALIDATING,
            self::RUNNING,
        ];
    }

    // --------------------------------------------------------------------------

    /**
     * Maps a set of cases onto their string values, for use in the database
     *
     * @param static[] $aStatuses
     *
     * @return string[]
     */
    public static function values(array $aStatuses): array
    {
        return array_map(fn(self $oStatus) => $oStatus->value, $aStatuses);
    }
}
