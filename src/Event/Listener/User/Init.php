<?php

namespace Nails\Auth\Event\Listener\User;

use Nails\Auth\Constants;
use Nails\Auth\Model\User;
use Nails\Auth\Service\Authentication;
use Nails\Common\Events;
use Nails\Common\Events\Subscription;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ModelException;
use Nails\Common\Exception\NailsException;
use Nails\Common\Service\DateTime;
use Nails\Common\Service\Session;
use Nails\Factory;
use ReflectionException;

/**
 * Class Init
 *
 * @package Nails\Common\Event\Listener\Locale
 */
class Init extends Subscription
{
    /**
     * Init constructor.
     */
    public function __construct()
    {
        $this
            ->setEvent(Events::SYSTEM_STARTING)
            ->setCallback([$this, 'execute']);
    }

    // --------------------------------------------------------------------------

    /**
     * Handles the event
     *
     * @throws FactoryException
     * @throws ModelException
     * @throws NailsException
     * @throws ReflectionException
     */
    public function execute(): void
    {
        $this
            ->instantiateUser()
            ->setTimezone()
            ->setDateFormat()
            ->setTimeFormat()
            ->checkUserIsSuspended();
    }

    // --------------------------------------------------------------------------

    /**
     * Find a remembered user and initialise the user model; this routine checks
     * the user's cookies and set's up the session for an existing or new user.
     *
     * @return $this
     * @throws FactoryException
     * @throws ModelException
     */
    protected function instantiateUser(): self
    {
        /** @var User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        $oUserModel->init();

        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Sets the user's timezone
     *
     * @return $this
     * @throws FactoryException
     */
    protected function setTimezone(): self
    {
        /** @var DateTime $oDateTimeService */
        $oDateTimeService = Factory::service('DateTime');
        $oDateTimeService
            ->setUserTimezone(
                activeUser('timezone') ?: $oDateTimeService->getTimezoneDefault()
            );

        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Sets the user's date format
     *
     * @return $this
     * @throws FactoryException
     */
    protected function setDateFormat(): self
    {
        /** @var DateTime $oDateTimeService */
        $oDateTimeService = Factory::service('DateTime');
        $oDateTimeService
            ->setUserDateFormat(
                activeUser('datetime_format_date') ?: $oDateTimeService->getDateFormatDefaultSlug()
            );

        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Sets the user's time format
     *
     * @return $this
     * @throws FactoryException
     */
    protected function setTimeFormat(): self
    {
        /** @var DateTime $oDateTimeService */
        $oDateTimeService = Factory::service('DateTime');
        $oDateTimeService
            ->setUserTimeFormat(
                activeUser('datetime_format_time') ?: $oDateTimeService->getTimeFormatDefaultSlug()
            );

        return $this;
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether the active user is suspended and, if so, logs them out.
     *
     * @return $this
     * @throws FactoryException
     * @throws NailsException
     * @throws ReflectionException
     */
    protected function checkUserIsSuspended(): self
    {
        //  Check if this user is suspended
        if (isLoggedIn() && activeUser('is_suspended')) {

            /** @var Authentication $oAuthService */
            $oAuthService = Factory::service('Authentication', Constants::MODULE_SLUG);
            get_instance()->lang->load('auth/auth');

            $oAuthService->logout();

            /** @var Session $oSession */
            $oSession = Factory::service('Session');
            $oSession->setFlashData('error', lang('auth_login_fail_suspended'));
            redirect('/');
        }

        return $this;
    }
}
