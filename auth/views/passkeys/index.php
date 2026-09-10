<?php

/**
 * @var \Nails\Auth\Resource\User\Passkey[] $aPasskeys
 */

use Nails\Common\Service\View;
use Nails\Factory;

/** @var View $oView */
$oView = Factory::service('View');

?>
<div class="nails-auth passkeys container container-md py-xl" data-passkey-site-url="<?=htmlspecialchars(siteUrl(), ENT_QUOTES)?>">
    <h1 class="text-center"><?=lang('auth_passkeys_title')?></h1>
    <p class="text-muted text-center">
        <?=lang('auth_passkeys_intro')?>
    </p>
    <?php

    $oView->load('auth/_components/alerts');

    ?>
    <div class="panel">
        <div class="panel__header">
            <h2 class="panel__title"><?=lang('auth_passkeys_add')?></h2>
        </div>
        <div class="panel__body">
            <div class="form__group">
                <label class="form__label" for="input-passkey-label">
                    <?=lang('auth_passkeys_label')?>
                </label>
                <input type="text" id="input-passkey-label" class="form__control" maxlength="100"
                       data-passkey-label placeholder="<?=lang('auth_passkeys_label_placeholder')?>">
                <small class="form__help"><?=lang('auth_passkeys_label_help')?></small>
            </div>
            <?php

            /**
             * Hidden until the JavaScript confirms the browser can do WebAuthn; a
             * browser which cannot is shown the notice below instead.
             */

            ?>
            <button type="button" class="btn btn--primary" data-passkey-register hidden>
                <?=lang('auth_passkeys_add')?>
            </button>
            <p class="form__feedback form__feedback--invalid" data-passkey-error hidden></p>
            <p class="text-muted" data-passkey-unsupported hidden>
                <?=lang('auth_passkeys_unsupported')?>
            </p>
        </div>
    </div>
    <?php

    /**
     * Nothing is rendered when there are none: the panel above already invites the
     * user to add one, so an empty state would only repeat it.
     */
    if (!empty($aPasskeys)) {
        ?>
        <div class="passkeys__table">
        <table class="table">
            <thead>
                <tr>
                    <th><?=lang('auth_passkeys_label')?></th>
                    <th><?=lang('auth_passkeys_added')?></th>
                    <th><?=lang('auth_passkeys_last_used')?></th>
                    <th class="text-right"><?=lang('auth_passkeys_actions')?></th>
                </tr>
            </thead>
            <tbody>
                <?php

                foreach ($aPasskeys as $oPasskey) {
                    ?>
                    <tr>
                        <td>
                            <?=form_open('auth/passkeys', 'class="form form--inline"')?>
                            <input type="hidden" name="action" value="rename">
                            <input type="hidden" name="id" value="<?=(int) $oPasskey->id?>">
                            <input type="text" name="label" class="form__control" maxlength="100"
                                   value="<?=htmlspecialchars((string) $oPasskey->label, ENT_QUOTES)?>">
                            <button type="submit" class="btn btn--sm btn--link">
                                <?=lang('auth_passkeys_rename')?>
                            </button>
                            <?=form_close()?>
                        </td>
                        <td class="passkeys__date" data-label="<?=lang('auth_passkeys_added')?>"
                            title="<?=toUserDatetime((string) $oPasskey->created)?>">
                            <?=niceTime(strtotime((string) $oPasskey->created))?>
                        </td>
                        <td class="passkeys__date" data-label="<?=lang('auth_passkeys_last_used')?>" <?=$oPasskey->last_used
                            ? 'title="' . toUserDatetime((string) $oPasskey->last_used) . '"'
                            : ''?>>
                            <?=$oPasskey->last_used
                                ? niceTime(strtotime((string) $oPasskey->last_used))
                                : lang('auth_passkeys_never_used')?>
                        </td>
                        <td class="text-right passkeys__actions">
                            <?=form_open('auth/passkeys', 'class="form form--inline"')?>
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="id" value="<?=(int) $oPasskey->id?>">
                            <button type="submit" class="btn btn--sm btn--danger"
                                    onclick="return confirm('<?=lang('auth_passkeys_remove_confirm')?>');">
                                <?=lang('auth_passkeys_remove')?>
                            </button>
                            <?=form_close()?>
                        </td>
                    </tr>
                    <?php
                }

                ?>
            </tbody>
        </table>
        </div>
        <?php
    }

    ?>
</div>
