<?php

namespace Nails\Auth\Auth\Admin\User\Tab;

use Nails\Auth\Interfaces\Admin\User\Tab;
use Nails\Auth\Resource\User;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ValidationException;
use Nails\Common\Exception\ViewNotFoundException;
use Nails\Common\Service\DateTime;
use Nails\Common\Service\View;
use Nails\Factory;

/**
 * Class Details
 *
 * @package Nails\Auth\Auth\Admin\User\Tab
 */
class Details implements Tab
{
    /**
     * Return the tab's label
     *
     * @return string
     */
    public function getLabel(): string
    {
        return 'Details';
    }

    // --------------------------------------------------------------------------

    /**
     * Return the order in which the tabs should render
     *
     * @return float|null
     */
    public function getOrder(): ?float
    {
        return 0;
    }

    // --------------------------------------------------------------------------

    /**
     * Return the tab's body
     *
     * @param User $oUser The user being edited
     *
     * @return string
     * @throws FactoryException
     * @throws ViewNotFoundException
     */
    public function getBody(User $oUser): string
    {
        /** @var View $oView */
        $oView = Factory::service('View');
        /** @var DateTime $oDateTimeService */
        $oDateTimeService = Factory::service('DateTime');

        return $oView
            ->load(
                [
                    'Accounts/edit/inc-basic',
                ],
                [
                    'oUser'            => $oUser,
                    'aFields'          => $this->getFields($oUser),
                    'sDefaultTimezone' => $oDateTimeService->getTimezoneDefault(),
                    'aTimezones'       => $oDateTimeService->getAllTimezone(),
                    'aDateFormats'     => $oDateTimeService->getAllDateFormat(),
                    'aTimeFormats'     => $oDateTimeService->getAllTimeFormat(),
                ],
                true
            );
    }

    // --------------------------------------------------------------------------

    /**
     * Returns additional markup, outside of the main <form> element
     *
     * @param User $oUser The user being edited
     *
     * @return string
     */
    public function getAdditionalMarkup(User $oUser): string
    {
        return '';
    }

    // --------------------------------------------------------------------------

    /**
     * Returns an array of validation rules compatible with Validator objects
     *
     * @param User $oUser The user being edited
     *
     * @return array
     */
    public function getValidationRules(User $oUser): array
    {
        /** @var \Nails\Auth\Model\User $oUserModel */
        $oUserModel = Factory::model('User', 'nails/module-auth');

        $aFields  = $this->getFields($oUser);
        $aRules   = [];
        $aInclude = [
            'first_name',
            'last_name',
            'username',
            'profile_img',
            'gender',
            'dob',
            'timezone',
            'datetime_format_date',
            'datetime_format_time',
        ];

        foreach ($aInclude as $sField) {
            $aRules[$sField] = getFromArray($sField, $aFields)->validation;
        }

        //  Validate username
        $aRules['username'][] = function ($sUsername) use ($oUser, $oUserModel) {
            if (!$oUserModel->isValidUsername($sUsername, true, $oUser->id)) {
                throw new ValidationException(
                    $oUserModel->lastError()
                );
            }
        };

        return $aRules;
    }

    // --------------------------------------------------------------------------

    /**
     * Return the user fields
     *
     * @param User $oUser The user being edited
     *
     * @return array
     * @throws FactoryException
     */
    protected function getFields(User $oUser): array
    {
        /** @var \Nails\Auth\Model\User $oUserModel */
        $oUserModel = Factory::model('User', 'nails/module-auth');

        $aFieldsDescribed = $oUserModel->describeFields();
        $aFields          = [];

        // The order and fields to include
        $aFieldOrder = [
            'first_name',
            'last_name',
            'username',
            'profile_img',
            'gender',
            'dob',
            'timezone',
            'datetime_format_date',
            'datetime_format_time',
            'ip_address',
            'last_ip',
            'created',
            'last_update',
            'login_count',
            'last_login',
            'referral',
            'referred_by',
        ];

        $aReadOnly = [
            'ip_address',
            'last_ip',
            'created',
            'last_update',
            'login_count',
            'last_login',
            'referral',
            'referred_by',
        ];

        $aCastToUserDateTime = [
            'created',
            'last_update',
            'last_login',
        ];

        $aCastToUser = [
            'referred_by',
        ];

        foreach ($aFieldOrder as $sField) {

            $oField = getFromArray($sField, $aFieldsDescribed);

            $oField->required = in_array('required', $oField->validation);
            $oField->readonly = in_array($oField->key, $aReadOnly);

            if (in_array($oField->key, $aCastToUserDateTime)) {
                $oField->default = toUserDatetime($oUser->{$oField->key});
            }

            if (in_array($oField->key, $aCastToUser)) {
                $oField->type = 'text';
                if (!empty($oUser->{$oField->key})) {

                    $oLookup = $oUserModel->getById($oUser->{$oField->key});

                    if (!empty($oLookup)) {
                        $oField->default = sprintf(
                            '#%s %s (%s)',
                            $oLookup->id,
                            trim($oLookup->first_name . ' ' . $oLookup->last_name),
                            $oLookup->email
                        );
                    }
                }
            }

            $aFields[$oField->key] = $oField;
        }

        return $aFields;
    }
}
