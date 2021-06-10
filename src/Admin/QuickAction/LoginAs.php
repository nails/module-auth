<?php

namespace Nails\Auth\Admin\QuickAction;

use Nails\Admin\Interfaces\QuickAction;
use Nails\Auth\Constants;
use Nails\Auth\Resource\User;
use Nails\Factory;

class LoginAs implements QuickAction
{
    public function getActions(string $sQuery, string $sOrigin): array
    {
        /** @var \Nails\Auth\Model\User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        /** @var \Nails\Auth\Model\User\Email $oUserEmailModel */
        $oUserEmailModel = Factory::model('UserEmail', Constants::MODULE_SLUG);

        $aUsers = $oUserModel->getAll([
            'limit'   => 5,
            'sort'    => [
                ['first_name', 'asc'],
                ['id', 'asc'],
            ],
            'where'   => [
                [$oUserModel->getTableAlias() . '.id !=', activeUser('id')],
            ],
            'or_like' => [
                [$oUserModel->getTableAlias() . '.first_name', $sQuery],
                [$oUserModel->getTableAlias() . '.last_name', $sQuery],
                [
                    sprintf(
                        'CONCAT_WS(" ", %s, %s)',
                        $oUserModel->getTableAlias() . '.first_name',
                        $oUserModel->getTableAlias() . '.last_name'
                    ),
                    $sQuery,
                    false,
                ],
                [$oUserEmailModel->getTableAlias() . '.email', $sQuery],
            ],
        ]);

        return array_map(function (User $oUser) use ($sOrigin) {
            return (object) [
                'label'    => sprintf(
                    'Log in as %s',
                    $oUser->name,
                ),
                'sublabel' => sprintf(
                    '%s, #%s',
                    $oUser->email,
                    $oUser->id
                ),
                'url'      => $oUser->getLoginUrl(null, $sOrigin),
            ];
        }, $aUsers);
    }
}
