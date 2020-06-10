<?php

use Nails\Common\Service\Input;
use Nails\Factory;
use Nails\Auth\Resource\User;

/**
 * @var User $oUser
 */

/** @var Input $oInput */
$oInput        = Factory::service('Input');
$aButtons      = [];
$sReturnString = '?return_to=' . urlencode(uri_string() . '?' . $oInput->server('QUERY_STRING'));

//  Login as
if ($oUser->id != activeUser('id') && userHasPermission('admin:auth:accounts:loginAs')) {

    //  Generate the return string
    $sUrl = uri_string();

    if ($oInput->get()) {

        //  Remove common problematic GET vars (for instance, we don't want isModal when we return)
        $get = $oInput->get();
        unset($get['isModal']);

        if ($get) {
            $sUrl .= '?' . http_build_query($get);
        }
    }

    $sReturnString = '?return_to=' . urlencode($sUrl);

    // --------------------------------------------------------------------------

    $sUrl = siteUrl('auth/override/login_as/' . md5($oUser->id) . '/' . md5($oUser->password) . $sReturnString);

    $aButtons[] = anchor(
        $sUrl,
        lang('admin_login_as') . ' ' . $oUser->first_name,
        'target="_parent"'
    );
}

// --------------------------------------------------------------------------

//  Suspend/restore
if ($oUser->is_suspended && activeUser('id') !== $oUser->id && userHasPermission('admin:auth:accounts:unsuspend')) {
    $aButtons[] = anchor(
        'admin/auth/accounts/unsuspend/' . $oUser->id . $sReturnString,
        lang('action_unsuspend')
    );

} elseif (!$oUser->is_suspended && activeUser('id') !== $oUser->id && userHasPermission('admin:auth:accounts:suspend')) {
    $aButtons[] = anchor(
        'admin/auth/accounts/suspend/' . $oUser->id . $sReturnString,
        lang('action_suspend')
    );
}

// --------------------------------------------------------------------------

if ($aButtons) {
    ?>
    <div class="btn-group dropup">
        <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
            Actions <span class="caret"></span>
        </button>
        <ul class="dropdown-menu">
            <?php
            foreach ($aButtons as $sButton) {
                echo '<li>' . $sButton . '</li>';
            }
            ?>
        </ul>
    </div>
    <?php
}
