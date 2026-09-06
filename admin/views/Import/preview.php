<?php

use Nails\Admin\Helper;
use Nails\Auth\Enum\User\Import\Status;
use Nails\Auth\Resource;

/**
 * @var string[]             $aKeys
 * @var Resource\User\Import $oImport
 * @var string               $sApproveUrl
 * @var string               $sListUrl
 * @var bool                 $bCsvMissing
 * @var array<int, string[]> $aRegistered
 */

$bIsDraft  = $oImport->status === Status::DRAFT;
$iColumns  = count($aKeys) + 1;
$iRowCount = (int) ($oImport->row_count ?? 0);

/**
 * Rows whose email or username already belongs to an account. The CSV is not
 * wrong - these rows are simply redundant - so rather than rejecting the file
 * the admin is offered the chance to import the rest without them.
 */
$aRegistered = $aRegistered ?? [];
$iRegistered = count($aRegistered);
$iImportable = max(0, $iRowCount - $iRegistered);

/**
 * The confirmation can be exact rather than hedging with "up to": both routes
 * to it - ticking the skip checkbox below, or Continue on the warning an
 * unticked Import opens - agree to skip exactly these rows.
 */
$sConfirmBody = sprintf(
    '%s %s will be created in the background%s. This cannot be undone.',
    number_format($iImportable),
    $iImportable === 1 ? 'user account' : 'user accounts',
    $iRegistered
        ? sprintf(
            ', and %s %s will be skipped',
            number_format($iRegistered),
            $iRegistered === 1 ? 'row' : 'rows'
        )
        : ''
);

/**
 * The warning an unticked Import opens, reusing the alert's own phrasing so the
 * modal does not introduce a second account of the same problem. Continue is an
 * implied tick, so the copy names both halves of what it agrees to.
 */
$sWarnTitle = sprintf(
    '%s %s already registered',
    number_format($iRegistered),
    $iRegistered === 1 ? 'row is' : 'rows are'
);

$sWarnBody = sprintf(
    'An account already exists for %s of the rows in this CSV, so %s cannot be imported. '
    . 'Continue to import the remaining %s and skip %s, or cancel and correct your CSV.',
    number_format($iRegistered),
    $iRegistered === 1 ? 'it' : 'they',
    number_format($iImportable),
    $iRegistered === 1 ? 'it' : 'them'
);

/**
 * Delete is a plain button which UserImport turns into a
 * DELETE /api/auth/import/{id}; see \Nails\Auth\Api\Controller\Import. Unlike
 * the approve form it does not degrade - with the bundle broken it does nothing
 * - which is the same bargain the preview table below already makes.
 *
 * `type="button"` is load bearing: on a draft this sits inside the approve
 * form, and the default `submit` would start the import.
 *
 * The status and the created count are handed over so that the confirmation
 * copy can be composed in one place, in JS, rather than written out again here.
 */
/**
 * Details is a plain button which UserImport turns into a modal; see
 * assets/js/components/UserImport.js. Offered only when there is something to
 * show - a stored reason, or rows which failed or warned - so a clean import
 * does not get a button which opens an empty box.
 */
$bHasDetails = $oImport->status->isTerminal()
    && ($oImport->error || $oImport->error_count || $oImport->warning_count);

$sDetailsButton = $bHasDetails
    ? sprintf(
        '<button type="button" class="btn btn-default js-user-import-details" data-import-id="%s">Details</button>',
        $oImport->id
    )
    : null;

$sDeleteButton = $oImport->status->isDeletable()
    ? sprintf(
        '<button type="button" class="btn btn-danger js-user-import-delete" %s>Delete</button>',
        implode(' ', [
            'data-import-id="' . $oImport->id . '"',
            'data-delete-url="' . siteUrl('api/auth/import/' . $oImport->id) . '"',
            'data-redirect="' . siteUrl($sListUrl) . '"',
            'data-status="' . $oImport->status->value . '"',
            //  Warned rows have accounts too; see Enum\User\Import\ItemStatus
            'data-created-count="' . ($oImport->success_count + $oImport->warning_count) . '"',
        ])
    )
    : null;

/**
 * A draft can be imported or deleted; anything else which is deletable gets
 * Delete alone, with nothing to save. A job a runner holds gets no controls at
 * all, so the bar is absent entirely for those two statuses.
 */
if ($bIsDraft) {
    $aControls = [
        'save' => ['text' => 'Import'],
        'html' => ['right' => $sDeleteButton],
    ];
} elseif ($sDeleteButton || $sDetailsButton) {
    $aControls = [
        'save' => ['enabled' => false],
        'html' => ['right' => trim($sDetailsButton . ' ' . $sDeleteButton)],
    ];
} else {
    $aControls = null;
}

/**
 * Mirrors how module-admin normalises a column label into a cell class; see
 * module-admin/admin/views/DefaultController/index.php
 */
$fFieldClass = function (string $sLabel): string {
    $sLabel = strtolower($sLabel);
    $sLabel = preg_replace('/[^a-z0-9 \-_]/', '', $sLabel);
    return 'field field--' . str_replace([' ', '_'], '-', $sLabel);
};

?>
<div class="module-auth import import--preview">
    <?php

    if ($bIsDraft) {
        /**
         * The approve form opts into a modal confirmation by carrying
         * `js-user-import-confirm`; UserImport intercepts the submit event, so
         * with the bundle broken the form still submits — unconfirmed, but
         * never dead.
         */
        $aFormAttributes = [
            'class="js-user-import-confirm"',
            'data-confirm-title="Start this import?"',
            'data-confirm-body="' . htmlspecialchars($sConfirmBody, ENT_QUOTES) . '"',
            'data-confirm-action="Import"',
        ];

        /**
         * The warning which stands in front of that confirmation when the skip
         * checkbox is unticked; `data-warn-field` names the checkbox Continue
         * ticks. Only when there is something to warn about - without these
         * UserImport goes straight to the confirmation, as it does everywhere
         * else.
         */
        if ($iRegistered) {
            $aFormAttributes[] = 'data-warn-field="skip_registered"';
            $aFormAttributes[] = 'data-warn-title="' . htmlspecialchars($sWarnTitle, ENT_QUOTES) . '"';
            $aFormAttributes[] = 'data-warn-body="' . htmlspecialchars($sWarnBody, ENT_QUOTES) . '"';
            $aFormAttributes[] = 'data-warn-action="Continue"';
        }

        echo form_open($sApproveUrl, implode(' ', $aFormAttributes));

        ?>
        <div class="alert alert-info">
            <strong>Please review the following data</strong>
            <br>Your CSV has been uploaded and every row has been validated. Please verify the values below, and
            when happy to continue, click "Import" below. The import runs in the background; you can leave this page
            once it has started.
        </div>
        <?php

        if ($iRegistered) {
            ?>
            <div class="alert alert-warning">
                <strong>
                    <?=number_format($iRegistered)?>
                    <?=$iRegistered === 1 ? 'row is' : 'rows are'?> already registered
                </strong>
                <br>An account already exists for the following, so
                <?=$iRegistered === 1 ? 'it' : 'they'?> cannot be imported. Tick the box below to import the
                remaining <?=number_format($iImportable)?>, or correct your CSV and upload it again.
                <div style="max-height: 10rem; overflow: auto; margin: 0.5rem 0 0;">
                    <?php

                    foreach ($aRegistered as $iLine => $aLineErrors) {
                        foreach ($aLineErrors as $sLineError) {
                            echo htmlspecialchars(
                                    sprintf('Line %d: %s', $iLine, $sLineError),
                                    ENT_QUOTES
                                ) . '<br>';
                        }
                    }

                    ?>
                </div>
                <?php
                /**
                 * Neither `required` nor a disabled Import button. Ticking is
                 * not the only valid answer - correcting the CSV and uploading
                 * it again is just as legitimate - and the decision is made at
                 * the Import button, a whole page of preview rows away from
                 * this warning, so it is asked for there instead: an unticked
                 * Import opens a warning modal, and Continue ticks this box on
                 * its way to the usual confirmation. See confirmForm() in
                 * assets/js/components/UserImport.js, which the `data-warn-*`
                 * attributes on the form above opt into.
                 *
                 * `required` was the previous arrangement, and it degraded
                 * better: with the bundle broken it blocked the submit in the
                 * page. Now an unticked Import posts, and approve() refuses it
                 * and redirects to the list with an error - a navigation where
                 * there used to be none. It has to go, though: native
                 * validation runs before the `submit` event, so the warning
                 * modal could never open. The job never starts either way; the
                 * guard was always server side.
                 */
                ?>
                <p style="margin: 0.5rem 0 0;">
                    <label>
                        <input type="checkbox" name="skip_registered" value="1">
                        Import the rest, skipping <?=$iRegistered === 1 ? 'this row' : 'these rows'?>
                    </label>
                </p>
            </div>
            <?php
        }

        ?>
        <?php
    } else {
        ?>
        <div class="alert alert-<?=$bHasDetails ? 'warning' : 'info'?>">
            This import is <strong><?=$oImport->status->value?></strong> and can no longer be changed.
            <?php
            if ($bHasDetails) {
                /**
                 * The summary line only; the stored error is multi-line by
                 * design (see Processor::composeError()) and the rest belongs in
                 * the modal the Details button opens.
                 */
                if ($oImport->error) {
                    ?>
                    <br><?=htmlspecialchars(explode("\n", $oImport->error, 2)[0], ENT_QUOTES)?>
                    <?php
                }
                ?>
                <br><br><?=$sDetailsButton?>
                <?php
            }
            ?>
        </div>
        <?php
    }

    ?>
    <div
        id="user-import-preview"
        data-import-id="<?=$oImport->id?>"
        <?=$bCsvMissing ? 'data-csv-missing="1"' : ''?>
    >
        <div class="user-import-preview-paging" data-paging="top">
            <?php

            /**
             * A stand-in for the paginator, so the page does not jump when the
             * first response lands; UserImport::renderPaginator() replaces the
             * contents of this slot wholesale.
             */
            if (!$bCsvMissing) {
                ?>
                <div class="pagination clearfix">
                    <small class="text-muted">Counting records&hellip;</small>
                    <div style="clear:both"></div>
                </div>
                <?php
            }

            ?>
        </div>
        <div class="table-responsive">
            <table>
                <thead>
                    <tr>
                        <th class="field field--line">Line</th>
                        <?php
                        foreach ($aKeys as $sKey) {
                            ?>
                            <th class="<?=$fFieldClass($sKey)?>"><?=$sKey?></th>
                            <?php
                        }
                        ?>
                    </tr>
                </thead>
                <tbody id="user-import-preview-body">
                    <tr>
                        <td colspan="<?=$iColumns?>" class="no-data">
                            <?php

                            if ($bCsvMissing) {
                                echo 'The CSV for this import is no longer available';
                            } else {
                                ?>
                                <span class="user-import-spinner" role="status" aria-hidden="true"></span>
                                Loading
                                <?php
                            }

                            ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <div class="user-import-preview-paging" data-paging="bottom"></div>
    </div>
    <?php

    if ($aControls) {
        echo Helper::floatingControls($aControls);
    }

    if ($bIsDraft) {
        echo form_close();
    }

    ?>
</div>
