<?php

/**
 * The API behind the user import admin: it backs the list of recent imports,
 * the paginated preview of an uploaded CSV, and the deletion of a job.
 *
 * Creating and approving a job stay on the admin controller - the upload needs
 * a multipart form, and both want flash messaging on the redirect which
 * follows. Deletion needs neither, so it lives here and the admin UI drives it
 * with fetch(), which is what lets the list drop a row without a reload. The
 * cost is that deleting from the preview screen no longer leaves a flash
 * message behind; on the list, the row vanishing is the better message anyway.
 *
 * This does not weaken CSRF protection. An HTML form can only issue GET or
 * POST, so no forged form can reach a DELETE route. A cross-origin fetch needs
 * a preflight, and module-api answers preflights with
 * `Access-Control-Allow-Origin: *` alongside `Access-Control-Allow-Credentials:
 * true` - a combination browsers reject for credentialed requests - so an
 * attacker's page cannot put the admin's session cookie on the request, and
 * without it the DELETE arrives unauthenticated and RestrictToAdmin turns it
 * away.
 *
 * Note that this rests on ApiRouter::ACCESS_CONTROL_ALLOW_ORIGIN staying a
 * wildcard: an app which overloads it to echo the request origin would make
 * every module-api mutation forgeable, this one included.
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Controller
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Api\Controller;

use Nails\Admin\Traits\Api\RestrictToAdmin;
use Nails\Api;
use Nails\Api\Exception\ApiException;
use Nails\Api\Factory\ApiResponse;
use Nails\Auth\Constants;
use Nails\Auth\Enum\User\Import\ItemStatus;
use Nails\Auth\Model;
use Nails\Auth\Resource;
use Nails\Auth\Service\User\Import\Csv;
use Nails\Cdn;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Common\Exception\NailsException;
use Nails\Common\Helper\Model\Expand;
use Nails\Common\Helper\Model\Sort;
use Nails\Common\Resource\Entity;
use Nails\Common\Service\HttpCodes;
use Nails\Common\Service\Input;
use Nails\Common\Service\Logger;
use Nails\Factory;
use stdClass;

/**
 * Class Import
 *
 * @package Nails\Auth\Api\Controller
 */
class Import extends Api\Controller\CrudController
{
    use RestrictToAdmin;

    // --------------------------------------------------------------------------

    const CONFIG_MODEL_NAME     = 'UserImport';
    const CONFIG_MODEL_PROVIDER = Constants::MODULE_SLUG;

    /**
     * The number of CSV rows shown per page of the preview
     *
     * @var int
     */
    const CONFIG_PER_PAGE = 25;

    // --------------------------------------------------------------------------

    public static function requirePermission(): ?string
    {
        return 'admin:auth:accounts:create';
    }

    // --------------------------------------------------------------------------

    protected function getLookupData(string $sMode, array $aData): array
    {
        return array_merge(
            parent::getLookupData($sMode, $aData),
            [
                new Expand('user'),
                new Expand('object'),
                new Sort('created', Sort::DESC),
            ]
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Jobs are created and approved through admin; the API can only read them
     * and delete them
     *
     * @param string                    $sAction The action being performed
     * @param Resource\User\Import|null $oItem   The item the action is being performed against
     *
     * @throws ApiException
     * @throws FactoryException
     */
    protected function userCan($sAction, ?Entity $oItem = null)
    {
        /** @var HttpCodes $oHttpCodes */
        $oHttpCodes = Factory::service('HttpCodes');

        if (in_array($sAction, [static::ACTION_CREATE, static::ACTION_UPDATE], true)) {
            throw new ApiException(
                'User imports cannot be created or modified via the API',
                $oHttpCodes::STATUS_METHOD_NOT_ALLOWED
            );
        }

        /**
         * A job which a runner is part way through is the one thing which must
         * not go: its rows are being written as we speak, and user_import_item
         * would cascade out from under it. A claim token means a runner has
         * taken the job whatever the status column says yet - claim() accepts
         * any active status, PENDING included - so that counts as in progress
         * too.
         *
         * 409 rather than 405: the verb is fine, it is the resource's state
         * which conflicts, and that lets the caller tell a stale list from a
         * genuine failure.
         */
        if ($sAction === static::ACTION_DELETE
            && (!$oItem->status->isDeletable() || $oItem->claim_token !== null)
        ) {
            throw new ApiException(
                'An import which is in progress cannot be deleted',
                $oHttpCodes::STATUS_CONFLICT
            );
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Formats the response object
     *
     * @param Resource\User\Import $oObj The object to format
     *
     * @throws FactoryException
     */
    protected function formatObject($oObj): stdClass
    {
        return (object) [
            'id'            => $oObj->id,
            'status'        => $oObj->status->value,
            'runner'        => $oObj->runner?->value,
            'progress'      => (object) [
                'row_count'       => $oObj->row_count,
                'validated_count' => $oObj->validated_count,
                'processed_count' => $oObj->processed_count,
                'success_count'   => $oObj->success_count,
                'warning_count'   => $oObj->warning_count,
                'error_count'     => $oObj->error_count,
                'percent'         => $oObj->getPercent(),
            ],
            /**
             * Both URLs are the guarded admin routes rather than CDN objects:
             * the files carry personal data, so the URL which reaches them is
             * minted behind a permission check and expires.
             *
             * They sit on `source` and `log` rather than on the `urls` object
             * below because `item.log.url` is what the admin JS already reads;
             * the inconsistency is deliberate. `source.url` has no consumer
             * yet, and is exposed so that a future link cannot be tempted back
             * to a direct CDN URL.
             */
            'source'        => (object) [
                'id'       => $oObj->object?->id,
                'filename' => $oObj->object?->file?->name?->human,
                'url'      => siteUrl('admin/auth/import/source/' . $oObj->id),
            ],
            'log'           => $oObj->log_id
                ? (object) [
                    'id'  => $oObj->log_id,
                    'url' => siteUrl('admin/auth/import/log/' . $oObj->id),
                ]
                : null,
            'error'         => $oObj->error,
            /**
             * The stored error is deliberately multi-line - see
             * Processor::composeError() - so anywhere with room for a single
             * line takes the summary rather than doing its own string surgery.
             */
            'error_summary' => $oObj->error
                ? explode("\n", $oObj->error, 2)[0]
                : null,
            'created'       => $oObj->created,
            'started'       => $oObj->started,
            'finished'      => $oObj->finished,
            'user'          => $oObj->user
                ? (object) [
                    'id'    => $oObj->user->id,
                    'name'  => $oObj->user->name,
                    'email' => $oObj->user->email,
                ]
                : null,
            'urls'          => (object) [
                'preview' => siteUrl('admin/auth/import/preview/' . $oObj->id),
                'approve' => siteUrl('admin/auth/import/approve/' . $oObj->id),
                'delete'  => siteUrl('api/auth/import/' . $oObj->id),
            ],
        ];
    }

    // --------------------------------------------------------------------------

    /**
     * Deletes a job, and the CDN objects behind it
     *
     * @param ApiResponse          $oApiResponse The API response
     * @param Resource\User\Import $oItem        The job being deleted
     *
     * @throws ApiException
     * @throws FactoryException
     * @throws ModelException
     */
    protected function delete(ApiResponse $oApiResponse, Entity $oItem): void
    {
        //  Applies the guard in userCan(), and 500s if the row will not go
        parent::delete($oApiResponse, $oItem);

        /**
         * Only now that the row is gone: object_id is ON DELETE CASCADE, so
         * destroying the CSV first would take the job with it - inside
         * objectDestroy()'s own transaction - and the delete above would then
         * report a job which no longer exists.
         *
         * A failure here is logged rather than raised: the row has gone and the
         * delete has genuinely succeeded, so there is nothing for the caller to
         * retry. It cannot be left silent though - `auth:user:import:clean`
         * reaps by walking job rows, so an object orphaned here is unreachable
         * by anything else, and the log line is the only thread back to what it
         * was for.
         */
        /** @var Model\User\Import $oModel */
        $oModel = $this->oModel;
        /** @var Logger $oLogger */
        $oLogger = Factory::service('Logger');

        foreach ($oModel->destroyObjects($oItem) as $iObjectId => $sError) {
            $oLogger->error(sprintf(
                'Failed to destroy CDN object #%s belonging to user import #%s; %s',
                $iObjectId,
                $oItem->id,
                $sError
            ));
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Returns a page of the job's CSV, as it will be imported
     *
     * Reached at GET /api/auth/import/{id}/rows; CrudController::read() routes
     * the trailing segment to a same-named method.
     *
     * @param ApiResponse          $oApiResponse The API response
     * @param Resource\User\Import $oItem        The job being previewed
     *
     * @throws ApiException
     * @throws FactoryException
     * @throws NailsException
     */
    protected function rows(ApiResponse $oApiResponse, Entity $oItem): void
    {
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var HttpCodes $oHttpCodes */
        $oHttpCodes = Factory::service('HttpCodes');
        /** @var Cdn\Service\Cdn $oCdn */
        $oCdn = Factory::service('Cdn', Cdn\Constants::MODULE_SLUG);
        /** @var Csv $oCsv */
        $oCsv = Factory::service('UserImportCsv', Constants::MODULE_SLUG);

        $sPath = $oCdn->objectLocalPath($oItem->object->id);
        if (empty($sPath)) {
            throw new ApiException(
                'The CSV for this import is no longer available',
                $oHttpCodes::STATUS_NOT_FOUND
            );
        }

        $iPage   = max(1, (int) $oInput->get(static::CONFIG_PAGE_PARAM));
        $iOffset = ($iPage - 1) * static::CONFIG_PER_PAGE;

        /**
         * row_count is recorded when the file is uploaded, so paging does not
         * have to re-scan the file on every request.
         */
        $iTotal = $oItem->row_count ?? $oCsv->countRows($sPath);

        $aRows = [];
        foreach ($oCsv->readRows($sPath, $iOffset, static::CONFIG_PER_PAGE) as $iLine => $aRow) {
            $aRows[] = (object) [
                'line' => $iLine,
                'data' => (object) $aRow,
            ];
        }

        $oApiResponse
            ->setData($aRows)
            ->setMeta([
                'header'     => $oCsv->getHeader($sPath),
                'pagination' => [
                    'page'     => $iPage,
                    'per_page' => static::CONFIG_PER_PAGE,
                    'total'    => $iTotal,
                    'previous' => $this->buildUrl($iTotal, $iPage, -1),
                    'next'     => $this->buildUrl($iTotal, $iPage, 1),
                ],
            ]);
    }

    // --------------------------------------------------------------------------

    /**
     * Returns a page of the job's per-row outcomes
     *
     * Reached at GET /api/auth/import/{id}/items; CrudController::read() routes
     * the trailing segment to a same-named method, as it does for rows().
     *
     * Kept off formatObject() deliberately: that runs for every row of the list,
     * and the list polls while any job is active, so folding per-row detail into
     * it would mean an extra query per job per poll for something nobody is
     * looking at until they click.
     *
     * @param ApiResponse          $oApiResponse The API response
     * @param Resource\User\Import $oItem        The job being inspected
     *
     * @throws ApiException
     * @throws FactoryException
     * @throws ModelException
     */
    protected function items(ApiResponse $oApiResponse, Entity $oItem): void
    {
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var Model\User\Import\Item $oItemModel */
        $oItemModel = Factory::model('UserImportItem', Constants::MODULE_SLUG);

        $aData = [
            'where' => [['import_id', $oItem->id]],
            'sort'  => [['line', 'ASC']],
        ];

        /**
         * A comma-separated list rather than a single value, so the details
         * modal can ask for the errors and the warnings - two statuses which
         * mean different things to the reader, but which are shown together - in
         * one request rather than two.
         */
        $aStatuses = $this->readStatuses((string) $oInput->get('status'));

        if (!empty($aStatuses)) {
            $aData['where_in'] = [['status', $aStatuses]];
        }

        $iPage  = max(1, (int) $oInput->get(static::CONFIG_PAGE_PARAM));
        $iTotal = $oItemModel->countAll($aData);

        $aItems = array_map(
            fn(Resource\User\Import\Item $oRow): stdClass => (object) [
                'line'    => $oRow->line,
                'status'  => $oRow->status->value,
                'message' => $oRow->message,
                'user_id' => $oRow->user_id,
            ],
            $oItemModel->getAll($iPage, static::CONFIG_PER_PAGE, $aData)
        );

        $oApiResponse
            ->setData($aItems)
            ->setMeta([
                'total_errors'   => $oItem->error_count,
                'total_warnings' => $oItem->warning_count,
                'pagination'     => [
                    'page'     => $iPage,
                    'per_page' => static::CONFIG_PER_PAGE,
                    'total'    => $iTotal,
                    'previous' => $this->buildUrl($iTotal, $iPage, -1),
                    'next'     => $this->buildUrl($iTotal, $iPage, 1),
                ],
            ]);
    }

    // --------------------------------------------------------------------------

    /**
     * Reads the `status` filter
     *
     * Every value is checked, and an unknown one is refused rather than dropped:
     * a filter which quietly ignores what it was asked for would answer a
     * question nobody asked.
     *
     * @param string $sStatus The requested status, or a comma-separated list of them
     *
     * @return string[] The statuses to filter by, empty for no filter
     * @throws ApiException If any of them is not a status
     * @throws FactoryException
     */
    protected function readStatuses(string $sStatus): array
    {
        /** @var HttpCodes $oHttpCodes */
        $oHttpCodes = Factory::service('HttpCodes');

        $aStatuses = [];

        foreach (array_filter(array_map('trim', explode(',', $sStatus))) as $sCandidate) {

            $oStatus = ItemStatus::tryFrom($sCandidate);

            if ($oStatus === null) {
                throw new ApiException(
                    sprintf('"%s" is not a valid item status', $sCandidate),
                    $oHttpCodes::STATUS_BAD_REQUEST
                );
            }

            $aStatuses[] = $oStatus->value;
        }

        return array_values(array_unique($aStatuses));
    }
}
