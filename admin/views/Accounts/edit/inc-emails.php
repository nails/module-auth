<?php

use Nails\Admin\Helper;
use Nails\Auth\Resource\User;

/**
 * @var User       $oUser
 * @var stdClass[] $aEmails
 */

?>
<table id="edit-user-emails" class="emails table table-striped table-hover table-bordered table-responsive">
    <thead class="table-dark">
        <tr>
            <th class="email"><?=lang('accounts_edit_emails_th_email')?></th>
            <th class="is-primary"><?=lang('accounts_edit_emails_th_primary')?></th>
            <th class="is-verified"><?=lang('accounts_edit_emails_th_verified')?></th>
            <th class="date-added"><?=lang('accounts_edit_emails_th_date_added')?></th>
            <th class="date-verified"><?=lang('accounts_edit_emails_th_date_verified')?></th>
            <th class="actions"><?=lang('accounts_edit_emails_th_actions')?></th>
        </tr>
    </thead>
    <tbody>
        <?php
        foreach ($aEmails as $oEmail) {
            ?>
            <tr data-email="<?=$oEmail->email?>" class="existing-email">
                <td class="email align-middle">
                    <?=mailto($oEmail->email)?>
                </td>
                <?=Helper::loadBoolCell($oEmail->is_primary)?>
                <?=Helper::loadBoolCell($oEmail->is_verified)?>
                <?=Helper::loadDateTimeCell($oEmail->date_added)?>
                <?=Helper::loadDateTimeCell($oEmail->date_verified, lang('accounts_edit_emails_td_not_verified'))?>
                <?php

                echo '<td class="actions align-middle">';
                if (!$oEmail->is_primary) {
                    echo anchor(
                        '',
                        'Make Primary',
                        'data-action="make-primary" class="btn btn-xs btn-primary"'
                    );
                    echo anchor(
                        '',
                        'Delete',
                        'data-action="delete" class="btn btn-xs btn-danger"'
                    );
                }

                if (!$oEmail->is_verified) {
                    echo anchor(
                        '',
                        'Verify',
                        'data-action="verify" class="btn btn-xs btn-success"'
                    );
                }
                echo '</td>';

                ?>
            </tr>
            <?php
        }
        ?>
        <tr id="add-email-form">
            <td class="email align-middle">
                <input type="email" name="email" class="mb-0" placeholder="Type an email address to add to the user here" />
            </td>
            <td class="is-primary text-center align-middle">
                <input type="checkbox" name="is_primary" value="1" />
            </td>
            <td class="is-verified text-center align-middle">
                <input type="checkbox" name="is_verified" value="1" />
            </td>
            <td class="date-added align-middle">
                <span class="text-muted">&mdash;</span>
            </td>
            <td class="date-verified align-middle">
                <span class="text-muted">&mdash;</span>
            </td>
            <td class="actions align-middle">
                <a href="#" class="submit btn btn-xs btn-success">Add Email</a>
            </td>
        </tr>
    </tbody>
</table>
