<?php

/**
 * This class provides the ability to merge users
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    AdminController
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Admin\Controller;

use Nails\Admin\Controller\Base;
use Nails\Admin\Factory\Nav;
use Nails\Admin\Helper;
use Nails\Auth\Admin\Permission;
use Nails\Auth\Constants;
use Nails\Auth\Model\User;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ValidationException;
use Nails\Common\Service\FormValidation;
use Nails\Common\Service\Input;
use Nails\Factory;
use stdClass;

/**
 * Class Merge
 *
 * @package Nails\Admin\Auth
 */
class Merge extends Base
{
    /**
     * Announces this controller's navGroups
     *
     * @return stdClass
     * @throws FactoryException
     */
    public static function announce()
    {
        if (userHasPermission(Permission\Users\Merge::class)) {
            /** @var Nav $oNavGroup */
            $oNavGroup = Factory::factory('Nav', \Nails\Admin\Constants::MODULE_SLUG);
            return $oNavGroup
                ->setLabel('Users')
                ->setIcon('fa-users')
                ->addAction('Merge Users');
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Merge users
     *
     * @return void
     * @throws FactoryException
     */
    public function index(): void
    {
        if (!userHasPermission(Permission\Users\Merge::class)) {
            unauthorised();
        }

        // --------------------------------------------------------------------------

        $this->setTitles(['Merge Users']);

        // --------------------------------------------------------------------------

        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);

        if ($oInput->post()) {
            try {

                /** @var FormValidation $oFormValidation */
                $oFormValidation = Factory::service('FormValidation');
                $oFormValidation
                    ->buildValidator([
                        'user_id'   => [
                            $oFormValidation::RULE_REQUIRED,
                        ],
                        'merge_ids' => [
                            $oFormValidation::RULE_REQUIRED,
                            function ($mInput) use ($oInput) {
                                $aMergeIds = explode(',', $mInput);
                                if (in_array(activeUser('id'), $aMergeIds)) {
                                    throw new ValidationException('You cannot list yourself as a user to merge.');

                                } elseif (in_array($oInput->post('user_id'), $aMergeIds)) {
                                    throw new ValidationException('You cannot merge the target user into itself.');
                                }
                            },
                        ],
                    ])
                    ->run();

                $oUserModel->merge(
                    (int) $oInput->post('user_id'),
                    explode(',', $oInput->post('merge_ids'))
                );

                $this->oUserFeedback->success('Users were merged successfully.');
                redirect(self::url());

            } catch (\Throwable $e) {
                $this->oUserFeedback->error($e->getMessage());
            }
        }

        Helper::loadView('index');
    }
}
