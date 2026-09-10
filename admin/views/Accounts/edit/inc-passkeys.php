<?php

/**
 * @var \Nails\Auth\Resource\User           $oUser
 * @var \Nails\Auth\Resource\User\Passkey[] $aPasskeys
 * @var bool                                $bEnabled
 */

use Nails\Admin\Helper;

if (!$bEnabled) {
    ?>
    <div class="alert alert-warning">
        <?=lang('accounts_edit_passkeys_disabled')?>
    </div>
    <?php
}

if (!empty($aPasskeys)) {
    ?>
    <div class="alert alert-warning">
        <?=lang('accounts_edit_passkeys_warning')?>
    </div>
    <?php
}

?>
<div class="table-responsive">
    <table class="table table-striped table-hover table-bordered table-responsive">
        <thead class="table-dark">
            <tr>
                <th class="field field--label">
                    <?=lang('accounts_edit_passkeys_label')?>
                </th>
                <th class="field field--added" width="200">
                    <?=lang('accounts_edit_passkeys_added')?>
                </th>
                <th class="field field--last-used" width="200">
                    <?=lang('accounts_edit_passkeys_last_used')?>
                </th>
                <th class="field field--synced boolean" width="150">
                    <?=lang('accounts_edit_passkeys_synced')?>
                </th>
                <th class="field field--transports">
                    <?=lang('accounts_edit_passkeys_details')?>
                </th>
                <th class="actions text-center" width="100">
                    <?=lang('accounts_edit_passkeys_revoke')?>
                </th>
            </tr>
        </thead>
        <tbody>
            <?php

            if (empty($aPasskeys)) {
                ?>
                <tr>
                    <td colspan="6" class="no-data">
                        <?=lang('accounts_edit_passkeys_none')?>
                    </td>
                </tr>
                <?php

            } else {
                foreach ($aPasskeys as $oPasskey) {

                    $aTransports = $oPasskey->getTransports();

                    ?>
                    <tr>
                        <td class="field field--label">
                            <?=htmlspecialchars((string) $oPasskey->label)?>
                        </td>
                        <?php

                        echo Helper::loadDateTimeCell((string) $oPasskey->created);
                        echo Helper::loadDateTimeCell($oPasskey->last_used ? (string) $oPasskey->last_used : null);
                        echo Helper::loadBoolCell($oPasskey->is_backed_up);

                        /**
                         * The AAGUID names the model of authenticator; it is only of use
                         * when supporting a user, so it sits under the transports rather
                         * than taking a column of its own.
                         */
                        echo Helper::loadCellAuto(
                            $aTransports
                                ? htmlspecialchars(implode(', ', $aTransports))
                                : null,
                            'field field--transports',
                            $oPasskey->aaguid
                                ? '<br><small class="text-muted">' . htmlspecialchars($oPasskey->aaguid) . '</small>'
                                : ''
                        );

                        ?>
                        <td class="actions text-center">
                            <input type="checkbox" name="passkey_revoke[]" value="<?=(int) $oPasskey->id?>">
                        </td>
                    </tr>
                    <?php
                }
            }

            ?>
        </tbody>
    </table>
</div>
