<?php

namespace Nails\Auth\Auth\Admin\User\Tab;

use Nails\Auth\Constants;
use Nails\Auth\Interfaces\Admin\User\Tab;
use Nails\Auth\Resource\User;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ViewNotFoundException;
use Nails\Common\Factory\Model\Field;
use Nails\Common\Helper\Form;
use Nails\Common\Service\Config;
use Nails\Common\Service\View;
use Nails\Factory;

/**
 * Class Meta
 *
 * @package Nails\Auth\Auth\Admin\User\Tab
 */
class Meta implements Tab
{
    /**
     * Return the tab's label
     *
     * @return string
     */
    public function getLabel(): string
    {
        return 'Meta';
    }

    // --------------------------------------------------------------------------

    public static function isEnabled(User $user): bool
    {
        return true;
    }

    // --------------------------------------------------------------------------

    /**
     * Return the order in which the tabs should render
     *
     * @return float|null
     */
    public function getOrder(): ?float
    {
        return 1;
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

        return $oView
            ->load(
                [
                    'Accounts/edit/inc-meta',
                ],
                [
                    'oUser'     => $oUser,
                    'aMetaCols' => $this->getFieldsForGroup($oUser->group_id),
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
        /** @var Field[] $aMetaCols */
        $aMetaCols = $this->getFieldsForGroup($oUser->group_id);
        $aRules    = [];

        foreach ($aMetaCols as $oField) {
            $aRules[$oField->getKey()] = $oField->getValidation();
        }

        return $aRules;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns a key/value array of columns and the data to populate
     *
     * @param User  $oUser The user being edited
     * @param array $aPost The POST array
     *
     * @return array
     */
    public function getPostData(User $oUser, array $aPost): array
    {
        $aMetaCols = $this->getFieldsForGroup($oUser->group_id);
        $aData     = [];

        /** @var Field $oField */
        foreach ($aMetaCols as $oField) {

            $sKey         = $oField->getKey();
            $aData[$sKey] = getFromArray($sKey, $aPost);

            switch ($oField->getType()) {

                //  @todo (Pablo - 2020-05-12) - Remove dependency on CDN module
                case 'cdn_object_picker':
                    $aData[$sKey] = (int) $aData[$sKey] ?: null;
                    break;

                case Form::FIELD_NUMBER:
                    if (empty($aData[$sKey]) && $aData[$sKey] !== '0' && $oField->isAllowNull()) {
                        $aData[$sKey] = null;
                    } else {
                        $aData[$sKey] = (int) $aData[$sKey];
                    }
                    break;

                case Form::FIELD_BOOLEAN:
                    $aData[$sKey] = (bool) $aData[$sKey];
                    break;
            }
        }

        return $aData;
    }

    // --------------------------------------------------------------------------

    /**
     * Returns the appropriate meta fields for the given user group
     *
     * @param int|null $iGroupId
     *
     * @return Field[]
     * @throws FactoryException
     */
    protected function getFieldsForGroup(?int $iGroupId = null): array
    {
        /** @var \Nails\Auth\Model\User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        /** @var Config $oConfig */
        $oConfig = Factory::service('Config');

        /** @var array|null $aDefinedCols */
        $aDefinedCols = $oConfig->item('user_meta_cols');
        /** @var Field[] $aMetaCols */
        $aMetaCols = $oUserModel->describeMetaFields();

        if ($iGroupId && is_array($aDefinedCols)) {

            if (!array_key_exists($iGroupId, $aDefinedCols)) {
                return $aMetaCols;
            }

            $aGroupFields = getFromArray($iGroupId, $aDefinedCols);

            //  @todo (Pablo - 2019-11-15) - Remove backwards compatability
            $oFirst = reset($aGroupFields);
            if (!is_string($oFirst)) {
                $aGroupFields = array_keys($aGroupFields);
            }

            return array_filter($aMetaCols, function (Field $oField) use ($aGroupFields) {
                return in_array($oField->getKey(), $aGroupFields);
            });

        } else {
            return $aMetaCols;
        }
    }
}
