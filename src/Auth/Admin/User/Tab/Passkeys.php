<?php

namespace Nails\Auth\Auth\Admin\User\Tab;

use Nails\Auth\Constants;
use Nails\Auth\Interfaces\Admin\User\Tab;
use Nails\Auth\Model\User\Passkey as PasskeyModel;
use Nails\Auth\Resource;
use Nails\Auth\Resource\User;
use Nails\Auth\Service\Passkey as PasskeyService;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Common\Service\View;
use Nails\Factory;

/**
 * Class Passkeys
 *
 * @package Nails\Auth\Auth\Admin\User\Tab
 */
class Passkeys implements Tab
{
    /**
     * Return the tab's label
     */
    public function getLabel(): string
    {
        return 'Passkeys';
    }

    // --------------------------------------------------------------------------

    /**
     * Whether the tab should be displayed or not
     */
    public static function isEnabled(User $user): bool
    {
        return userHasPermission('admin:auth:accounts:editOthers');
    }

    // --------------------------------------------------------------------------

    /**
     * Return the order in which the tabs should render
     */
    public function getOrder(): ?float
    {
        return 3.6;
    }

    // --------------------------------------------------------------------------

    /**
     * Return the tab's body
     *
     * @throws FactoryException
     * @throws ModelException
     */
    public function getBody(User $oUser): string
    {
        /** @var View $oView */
        $oView = Factory::service('View');
        /** @var PasskeyModel $oModel */
        $oModel = Factory::model('UserPasskey', Constants::MODULE_SLUG);
        /** @var PasskeyService $oService */
        $oService = Factory::service('Passkey', Constants::MODULE_SLUG);

        return $oView->load(
            ['Accounts/edit/inc-passkeys'],
            [
                'oUser'     => $oUser,
                'aPasskeys' => $oModel->getByUserId((int) $oUser->id),
                'bEnabled'  => $oService->isEnabled(),
            ],
            true
        );
    }

    // --------------------------------------------------------------------------

    /**
     * Returns additional markup, outside of the main <form> element
     */
    public function getAdditionalMarkup(User $oUser): string
    {
        return '';
    }

    // --------------------------------------------------------------------------

    /**
     * Returns an array of validation rules compatible with Validator objects
     *
     * @return array<string, mixed>
     */
    public function getValidationRules(User $oUser): array
    {
        return [];
    }

    // --------------------------------------------------------------------------

    /**
     * Revokes the checked passkeys
     *
     * Revocation happens here rather than through the returned array because a passkey
     * is a row of its own, not a column on the user.
     *
     * @param array<string, mixed> $aPost The POST array
     *
     * @return array<string, mixed>
     * @throws FactoryException
     * @throws ModelException
     */
    public function getPostData(User $oUser, array $aPost): array
    {
        /** @var PasskeyService $oService */
        $oService = Factory::service('Passkey', Constants::MODULE_SLUG);
        /** @var PasskeyModel $oModel */
        $oModel = Factory::model('UserPasskey', Constants::MODULE_SLUG);

        $aRevoke = array_filter((array) ($aPost['passkey_revoke'] ?? []));

        foreach ($aRevoke as $mId) {

            /** @var Resource\User\Passkey|null $oPasskey */
            $oPasskey = $oModel->getById((int) $mId);

            //  Only ever this user's own; a stray ID must not revoke somebody else's
            if (empty($oPasskey) || (int) $oPasskey->user_id !== (int) $oUser->id) {
                continue;
            }

            $oService->revoke($oPasskey, ['by_admin' => (int) activeUser('id')]);
        }

        return [];
    }
}
