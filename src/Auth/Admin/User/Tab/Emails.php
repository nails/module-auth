<?php

namespace Nails\Auth\Auth\Admin\User\Tab;

use Nails\Auth\Admin\Controller\Accounts;
use Nails\Auth\Constants;
use Nails\Auth\Interfaces\Admin\User\Tab;
use Nails\Auth\Resource\User;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ViewNotFoundException;
use Nails\Common\Service\Input;
use Nails\Common\Service\View;
use Nails\Factory;

/**
 * Class Emails
 *
 * @package Nails\Auth\Auth\Admin\User\Tab
 */
class Emails implements Tab
{
    /**
     * Return the tab's label
     *
     * @return string
     */
    public function getLabel(): string
    {
        return 'Email Addresses';
    }

    // --------------------------------------------------------------------------

    /**
     * Return the order in which the tabs should render
     *
     * @return float|null
     */
    public function getOrder(): ?float
    {
        return 2;
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
        /** @var \Nails\Auth\Model\User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);

        return $oView
            ->load(
                [
                    'Accounts/edit/inc-emails',
                ],
                [
                    'oUser'   => $oUser,
                    'aEmails' => $oUserModel->getEmailsForUser($oUser->id),
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
        /** @var Input $oInput */
        $oInput = Factory::service('Input');

        return
            form_open(Accounts::url('email'), 'id="email-form"') .
            form_hidden('id', $oUser->id) .
            form_hidden('return', uri_string() . '?' . $oInput->server('QUERY_STRING')) .
            form_hidden('email') .
            form_hidden('action') .
            form_hidden('is_primary') .
            form_hidden('is_verified') .
            form_close();
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
        return [];
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
        return [];
    }
}
