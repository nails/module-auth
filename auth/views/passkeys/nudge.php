<?php

/**
 * @var string $sReturnTo
 */

use Nails\Common\Service\View;
use Nails\Factory;

/** @var View $oView */
$oView = Factory::service('View');

?>
<div class="nails-auth passkeys-nudge container center-screen" data-passkey-site-url="<?=htmlspecialchars(siteUrl(), ENT_QUOTES)?>">
    <div class="panel">
        <div class="panel__header">
            <h1 class="panel__title text-center">
                <?=lang('auth_passkeys_nudge_title')?>
            </h1>
        </div>
        <div class="panel__body">
            <?php

            $oView->load('auth/_components/alerts');

            ?>
            <p class="text-center">
                <?=lang('auth_passkeys_nudge_body')?>
            </p>
            <div class="form__actions">
                <?php

                /**
                 * Un-hidden by the JavaScript once it establishes there is a platform
                 * authenticator to enrol; when there is not, it posts "skip" for us so
                 * the user is never asked again on a browser which cannot oblige.
                 */

                ?>
                <button type="button" class="btn btn--block btn--primary" data-passkey-register
                        data-passkey-nudge data-passkey-redirect="<?=htmlspecialchars($sReturnTo, ENT_QUOTES)?>"
                        hidden>
                    <?=lang('auth_passkeys_nudge_add')?>
                </button>
                <p class="form__feedback form__feedback--invalid" data-passkey-error hidden></p>
                <?=form_open('auth/passkeys/nudge', 'class="form"')?>
                <input type="hidden" name="action" value="dismiss">
                <button type="submit" class="btn btn--block btn--link">
                    <?=lang('auth_passkeys_nudge_skip')?>
                </button>
                <?=form_close()?>
                <?php

                /**
                 * A no-JS visitor cannot enrol a passkey at all, so they get a plain link
                 * onwards rather than a button which would do nothing.
                 */

                ?>
                <noscript>
                    <?=anchor($sReturnTo, lang('auth_passkeys_nudge_continue'), 'class="btn btn--block btn--link"')?>
                </noscript>
            </div>
        </div>
    </div>
</div>
<?php

/**
 * Submitted automatically by the JavaScript when the browser reports no platform
 * authenticator to enrol. Built with form_open() so that it carries whatever CSRF
 * token the framework is configured to require.
 */

echo form_open('auth/passkeys/nudge', 'id="passkey-nudge-skip" hidden');
echo form_hidden('action', 'skip');
echo form_close();
