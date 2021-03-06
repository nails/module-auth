<?php

use Nails\Common\Service\View;
use Nails\Factory;

/** @var View $oView */
$oView = Factory::service('View');

$aQuery = array_filter([
    'return_to' => $return_to,
    'remember'  => $remember,
]);

$sQuery   = !empty($aQuery) ? '?' . http_build_query($aQuery) : '';
$sFormUrl = null;

if (isset($user_id) && isset($token)) {
    $sFormUrl = 'auth/mfa/device/' . $user_id . '/' . $token['salt'] . '/' . $token['token'] . $sQuery;
    $sFormUrl = siteUrl($sFormUrl);
}

?>
<div class="nails-auth mfa mfa--device mfa--device--ask u-center-screen">
    <div class="panel">
        <h1 class="panel__header text-center">
            Two Factor Authentication
        </h1>
        <div class="panel__body">
            <?php

            $oView->load('auth/_components/alerts');

            echo form_open($sFormUrl);

            $sFieldKey         = 'mfa_code';
            $sFieldLabel       = 'Please enter a code generated by your device:';
            $sFieldPlaceholder = 'Enter a code generated by your device';
            $sFieldAttr        = 'id="input-' . $sFieldKey . '" autocomplete="off" placeholder="' . $sFieldPlaceholder . '"';

            ?>
            <div class="form__group <?=form_error($sFieldKey) ? 'has-error' : ''?>">
                <label for="input-<?=$sFieldKey?>"><?=$sFieldLabel?></label>
                <?=form_text($sFieldKey, set_value($sFieldKey), $sFieldAttr)?>
                <?=form_error($sFieldKey, '<p class="form__error">', '</p>')?>
            </div>
            <p>
                <button type="submit" class="btn btn--block btn--primary">
                    Verify code &amp; Sign in
                </button>
            </p>
            <?=form_close()?>
        </div>
    </div>
</div>
