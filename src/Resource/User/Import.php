<?php

namespace Nails\Auth\Resource\User;

use Nails\Auth\Enum\User\Import\Runner;
use Nails\Auth\Enum\User\Import\Status;
use Nails\Cdn\Resource\CdnObject;
use Nails\Common\Model\Base;
use Nails\Common\Resource;
use Nails\Common\Resource\DateTime;
use Nails\Common\Resource\Entity;
use stdClass;

/**
 * Class Import
 *
 * @package Nails\Auth\Resource\User
 */
class Import extends Entity
{
    /**
     * The CDN Object containing the source CSV
     */
    public int $object_id;

    /**
     * The CDN Object containing the generated log CSV, if one has been generated
     */
    public ?int $log_id;

    /**
     * The additional field values which will be applied to every user
     */
    public stdClass $additional;

    /**
     * Whether rows which are already registered should be skipped rather than
     * rejecting the whole file; chosen by the admin when the job is approved
     */
    public bool $skip_registered;

    public Status  $status;
    public ?Runner $runner;

    /**
     * The token held by the process which is currently working this job
     */
    public ?string   $claim_token;
    public ?DateTime $claimed;

    /**
     * The reason the job was rejected, when FAILED
     */
    public ?string $error;

    /**
     * The number of data rows in the CSV, the header excluded
     */
    public ?int $row_count;

    public int $validated_count;
    public int $processed_count;
    public int $success_count;

    /**
     * Rows whose account was created, but whose after-create hook failed; see
     * Service\User\Import::afterUserCreate()
     */
    public int $warning_count;

    public int $error_count;

    public ?DateTime $started;
    public ?DateTime $finished;

    /**
     * Expandable relationships
     */
    public ?Resource   $user   = null;
    public ?CdnObject  $object = null;
    public ?CdnObject  $log    = null;

    // --------------------------------------------------------------------------

    public function __construct(self|stdClass|array $resource = [], ?Base $model = null)
    {
        $resource->status = $resource->status instanceof Status
            ? $resource->status
            : Status::from($resource->status);

        $resource->runner = $resource->runner instanceof Runner || $resource->runner === null
            ? $resource->runner
            : Runner::tryFrom($resource->runner);

        if (!$resource->additional instanceof stdClass) {
            $resource->additional = (object) (json_decode((string) $resource->additional) ?: []);
        }

        $resource->skip_registered = (bool) ($resource->skip_registered ?? false);
        $resource->warning_count   = (int) ($resource->warning_count ?? 0);

        parent::__construct($resource, $model);
    }

    // --------------------------------------------------------------------------

    /**
     * The percentage of the job which has been completed
     */
    public function getPercent(): int
    {
        if (empty($this->row_count)) {
            return 0;

        } elseif ($this->status->isTerminal()) {
            return 100;
        }

        $iCursor = $this->status === Status::VALIDATING
            ? $this->validated_count
            : $this->processed_count;

        return (int) min(100, floor(($iCursor / $this->row_count) * 100));
    }
}
