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
if ($oUser->id != activeUser('id') && userHasPermission(\Nails\Auth\Admin\Permission\Users\LoginAs::class)) {

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

    $aButtons[] = anchor(
        $oUser->getLoginUrl(),
        'Log in as ' . $oUser->name,
        'class="btn btn-default" target="_parent"'
    );
}

// --------------------------------------------------------------------------

//  Suspend/restore
if ($oUser->is_suspended && activeUser('id') !== $oUser->id && userHasPermission(\Nails\Auth\Admin\Permission\Users\Suspend::class)) {
    $aButtons[] = anchor(
        'admin/auth/accounts/unsuspend/' . $oUser->id . $sReturnString,
        lang('action_unsuspend'),
        'class="btn btn-success"'
    );

} elseif (!$oUser->is_suspended && activeUser('id') !== $oUser->id && userHasPermission(\Nails\Auth\Admin\Permission\Users\Suspend::class)) {
    $aButtons[] = anchor(
        'admin/auth/accounts/suspend/' . $oUser->id . $sReturnString,
        lang('action_suspend'),
        'class="btn btn-danger"'
    );
}

// --------------------------------------------------------------------------

foreach ($aButtons as $sButton) {
    echo $sButton . ' ';
}
