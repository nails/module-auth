<?php

/**
 * Returns information about the currently logged in user
 *
 * @package     Nails
 * @subpackage  module-api
 * @category    Controller
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Api\Controller;

use Nails\Api;
use Nails\Auth\Admin\Permission;
use Nails\Auth\Constants;
use Nails\Factory;

class User extends Api\Controller\DefaultController
{
    const CONFIG_MODEL_NAME         = 'User';
    const CONFIG_MODEL_PROVIDER     = Constants::MODULE_SLUG;
    const CONFIG_MIN_SEARCH_LENGTH  = 2;
    const CONFIG_POST_IGNORE_FIELDS = [
        'id',
        'slug',
        'created',
        'is_deleted',
        'created_by',
        'modified',
        'modified_by',
        'id_md5',
        'group_id',
        'ip_address',
        'last_ip',
        'username',
        'password',
        'password_md5',
        'password_engine',
        'password_changed',
        'salt',
        'forgotten_password_code',
        'remember_code',
        'last_login',
        'last_seen',
        'is_suspended',
        'temp_pw',
        'failed_login_count',
        'failed_login_expires',
        'last_update',
        'user_acl',
        'login_count',
        'referral',
        'referred_by',
    ];

    // --------------------------------------------------------------------------

    /**
     * Search for an item
     *
     * @return \Nails\Api\Factory\ApiResponse
     */
    public function getSearch($aData = [])
    {
        if (!userHasPermission(Permission\Users\Browse::class)) {
            $oHttpCodes = Factory::service('HttpCodes');
            throw new Api\Exception\ApiException(
                'You are not authorised to search users',
                $oHttpCodes::STATUS_UNAUTHORIZED
            );
        }

        return parent::getSearch($aData);
    }

    // --------------------------------------------------------------------------

    /**
     * Returns a user by their email
     *
     * @return array
     */
    public function getEmail()
    {
        /** @var \Nails\Common\Service\HttpCodes $oHttpCodes */
        $oHttpCodes = Factory::service('HttpCodes');

        if (!userHasPermission(Permission\Users\Browse::class)) {
            throw new Api\Exception\ApiException(
                'You are not authorised to browse users',
                $oHttpCodes::STATUS_UNAUTHORIZED
            );
        }

        /** @var \Nails\Common\Service\Input $oInput */
        $oInput = Factory::service('Input');
        $sEmail = $oInput->get('email');

        if (!valid_email($sEmail)) {
            throw new Api\Exception\ApiException(
                '"' . $sEmail . '" is not a valid email',
                $oHttpCodes::STATUS_BAD_REQUEST
            );
        }

        $oUserModel = Factory::model(static::CONFIG_MODEL_NAME, static::CONFIG_MODEL_PROVIDER);
        $oUser      = $oUserModel->getByEmail($sEmail);

        if (empty($oUser)) {
            throw new Api\Exception\ApiException(
                'No user found for email "' . $sEmail . '"',
                $oHttpCodes::STATUS_NOT_FOUND
            );
        }

        return Factory::factory('ApiResponse', Api\Constants::MODULE_SLUG)
            ->setData($this->formatObject($oUser));
    }

    // --------------------------------------------------------------------------

    /**
     * Creates or updates a user
     *
     * @return array
     */
    public function postRemap()
    {
        /** @var \Nails\Common\Service\Uri $oUri */
        $oUri    = Factory::service('Uri');
        $iItemId = (int) $oUri->segment(4);

        if ($iItemId && !userHasPermission(Permission\Users\Edit::class)) {
            return [
                'status' => 401,
                'error'  => 'You do not have permission to update this resource',
            ];

        } elseif (!$iItemId && !userHasPermission(Permission\Users\Create::class)) {
            return [
                'status' => 401,
                'error'  => 'You do not have permission to create this type of resource',
            ];
        }

        return parent::postRemap();
    }

    // --------------------------------------------------------------------------

    /**
     * Format the output
     *
     * @param \stdClass $oObj The object to format
     *
     * @return array
     */
    public function formatObject($oObj)
    {
        return [
            'id'         => $oObj->id,
            'label'      => sprintf('%s - %s', trim($oObj->first_name . ' ' . $oObj->last_name), $oObj->email),
            'first_name' => $oObj->first_name,
            'last_name'  => $oObj->last_name,
            'email'      => $oObj->email,
        ];
    }
}
