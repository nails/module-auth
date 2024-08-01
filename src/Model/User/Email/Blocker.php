<?php

/**
 * This model contains all methods for interacting with user emails.
 *
 * @package    Nails
 * @subpackage module-auth
 * @category   Model
 * @author     Nails Dev Team
 */

namespace Nails\Auth\Model\User\Email;

use Nails\Auth\Constants;
use Nails\Common\Exception\ValidationException;
use Nails\Common\Helper\Form;
use Nails\Common\Model\Base;
use Nails\Email\Resource\Type;
use Nails\Email\Service\Emailer;
use Nails\Factory;

/**
 * Class Blocker
 *
 * @package Nails\Auth\Model\User\Email
 */
class Blocker extends Base
{
    /**
     * The table this model represents
     *
     * @var string
     */
    const TABLE = NAILS_DB_PREFIX . 'user_email_blocker';

    /**
     * The name of the resource to use (as passed to \Nails\Factory::resource())
     *
     * @var string
     */
    const RESOURCE_NAME = 'UserEmailBlocker';

    /**
     * The provider of the resource to use (as passed to \Nails\Factory::resource())
     *
     * @var string
     */
    const RESOURCE_PROVIDER = Constants::MODULE_SLUG;

    // --------------------------------------------------------------------------

    public function getSearchableColumns(): array
    {
        $userModel = Factory::model('User', Constants::MODULE_SLUG);
        return [
            sprintf(
                '(SELECT CONCAT_WS(" ", u.first_name, u.last_name) from `%s` u WHERE u.id = %s.user_id)',
                $userModel->getTableName(),
                $this->getTableAlias()
            ),
        ];
    }

    // --------------------------------------------------------------------------

    public function describeFields($sTable = null)
    {
        $fields = parent::describeFields($sTable);

        /** @var Emailer $emailer */
        $emailer = Factory::service('Emailer', \Nails\Email\Constants::MODULE_SLUG);

        $fields['user_id']
            ->setLabel('User')
            ->setClass('user-search')
            ->setIsRequired(true);

        $fields['type']
            ->setIsRequired(true)
            ->setType(Form::FIELD_DROPDOWN)
            ->setClass('select2')
            ->setPlaceholder('Select a value')
            ->setOptions(array_merge(
                [
                    '' => '',
                ],
                array_filter(
                    array_map(
                        fn(Type $type) => $type->canUnsubscribe() ? $type->name : null,
                        $emailer->getTypes()
                    )
                )
            ))
            ->addValidation(function ($value) use ($emailer) {
                $type = $emailer->getType($value);
                if ($type && !$type->canUnsubscribe()) {
                    throw new ValidationException('It is not possible to unsubscribe from this type of email');
                }
            });

        return $fields;
    }
}
