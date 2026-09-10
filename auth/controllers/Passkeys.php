<?php

/**
 * Passkey self-service management
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Controller
 * @author      Nails Dev Team
 * @link
 */

use Nails\Auth\Constants;
use Nails\Auth\Controller\Base;
use Nails\Auth\Exception\Passkey\PasskeyException;
use Nails\Auth\Model\User\Passkey as PasskeyModel;
use Nails\Auth\Resource;
use Nails\Auth\Service\Passkey as PasskeyService;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Service\Asset;
use Nails\Common\Service\Input;
use Nails\Common\Service\UserFeedback;
use Nails\Common\Service\View;
use Nails\Factory;

/**
 * Class Passkeys
 */
class Passkeys extends Base
{
    /**
     * Passkeys constructor.
     *
     * @throws FactoryException
     */
    public function __construct()
    {
        parent::__construct();

        if (!isLoggedIn()) {
            unauthorised('Please log in to manage your passkeys.');
        }

        if (!$this->passkeyService()->isEnabled()) {
            show404();
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Lists, renames and removes the active user's passkeys
     *
     * The rename and remove actions are plain form posts so that the page keeps
     * working without JavaScript; only adding a passkey needs the browser API.
     *
     * @throws FactoryException
     */
    public function index(): void
    {
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var UserFeedback $oUserFeedback */
        $oUserFeedback = Factory::service('UserFeedback');
        /** @var View $oView */
        $oView = Factory::service('View');
        /** @var PasskeyModel $oModel */
        $oModel = Factory::model('UserPasskey', Constants::MODULE_SLUG);

        $sAction = (string) $oInput::post('action');

        if ($sAction === 'rename' || $sAction === 'remove') {

            try {

                $oPasskey = $this->requireOwnedPasskey((int) $oInput::post('id'));

                if ($sAction === 'rename') {

                    $sLabel = trim((string) $oInput::post('label'));

                    if ($sLabel === '') {
                        throw new PasskeyException(lang('auth_passkeys_label_required'));
                    }

                    $this->passkeyService()->rename($oPasskey, $sLabel);
                    $oUserFeedback->success(lang('auth_passkeys_renamed'));

                } else {
                    $this->passkeyService()->revoke($oPasskey);
                    $oUserFeedback->success(lang('auth_passkeys_removed'));
                }

            } catch (PasskeyException $e) {
                $oUserFeedback->error($e->getMessage());
            }

            redirect('auth/passkeys');
        }

        // --------------------------------------------------------------------------

        $this->data['aPasskeys'] = $oModel->getByUserId((int) activeUser('id'));

        $this->oMetaData->setTitles([lang('auth_passkeys_title')]);

        $this->loadPageAssets('index');

        $oView
            ->load([
                'structure/header/blank',
                'auth/passkeys/index',
                'structure/footer/blank',
            ]);
    }

    // --------------------------------------------------------------------------

    /**
     * Offers a passkey to a user who has just signed in with a password
     *
     * @throws FactoryException
     */
    public function nudge(): void
    {
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var View $oView */
        $oView = Factory::service('View');

        $oService  = $this->passkeyService();
        $sReturnTo = $oService->consumeNudgeReturn() ?: (string) activeUser()->group_homepage;

        $sAction = (string) $oInput::post('action');

        if ($sAction === 'skip' || $sAction === 'dismiss') {

            /**
             * "skip" is this browser saying it has no platform authenticator, "dismiss"
             * is the user saying no; either way, stop asking on this browser.
             */
            $oService->setNudgeDismissed();

            redirect($sReturnTo);
        }

        //  Reading it consumed it, so put it back for the form to post against
        $oService->markNudged($sReturnTo);

        $this->data['sReturnTo'] = $sReturnTo;

        $this->oMetaData->setTitles([lang('auth_passkeys_nudge_title')]);

        $this->loadPageAssets('nudge');

        $oView
            ->load([
                'structure/header/blank',
                'auth/passkeys/nudge',
                'structure/footer/blank',
            ]);
    }

    // --------------------------------------------------------------------------

    /**
     * Loads the styles and JavaScript this page needs, unless the app overrode it
     *
     * @throws FactoryException
     */
    protected function loadPageAssets(string $sView): void
    {
        $sAppView = \Nails\Config::get('NAILS_APP_PATH')
            . 'application/modules/auth/views/passkeys/' . $sView . '.php';

        $this->loadStyles($sAppView);

        if (!$this->isViewOverridden($sAppView)) {
            /** @var Asset $oAsset */
            $oAsset = Factory::service('Asset');
            $oAsset->load('passkey.min.js', Constants::MODULE_SLUG, 'JS', false, true);
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Resolves a passkey ID, refusing anybody else's
     *
     * @throws FactoryException
     * @throws PasskeyException
     */
    protected function requireOwnedPasskey(int $iId): Resource\User\Passkey
    {
        /** @var PasskeyModel $oModel */
        $oModel = Factory::model('UserPasskey', Constants::MODULE_SLUG);

        /** @var Resource\User\Passkey|null $oPasskey */
        $oPasskey = $oModel->getById($iId);

        if (empty($oPasskey) || (int) $oPasskey->user_id !== (int) activeUser('id')) {
            throw new PasskeyException(lang('auth_passkeys_not_found'));
        }

        return $oPasskey;
    }

    // --------------------------------------------------------------------------

    /**
     * @throws FactoryException
     */
    protected function passkeyService(): PasskeyService
    {
        /** @var PasskeyService $oService */
        $oService = Factory::service('Passkey', Constants::MODULE_SLUG);

        return $oService;
    }
}
