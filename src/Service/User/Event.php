<?php

/**
 * This service provides convinience methods for interacting with user events.
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Service
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Service\User;

use Nails\Auth\Constants;
use Nails\Auth\Exception\AuthException;
use Nails\Common\Exception\ModelException;
use Nails\Common\Factory\Component;
use Nails\Common\Traits\ErrorHandling;
use Nails\Components;
use Nails\Environment;
use Nails\Factory;

/**
 * Class Event
 *
 * @package Nails\Auth\Service\User
 */
class Event
{
    use ErrorHandling;

    // --------------------------------------------------------------------------

    /**
     * The Event model
     *
     * @var \Nails\Auth\Model\User\Event
     */
    protected $oModel;

    // --------------------------------------------------------------------------

    /**
     * Event constructor.
     *
     * @throws \Nails\Common\Exception\FactoryException
     */
    public function __construct()
    {
        $this->oModel = Factory::model('UserEvent', Constants::MODULE_SLUG);
    }

    // --------------------------------------------------------------------------

    /**
     * Creates a new user event
     *
     * @param string      $sType      The event type to create
     * @param null        $mData      Any data to record alongside the event
     * @param int|null    $iRef       A numeric reference to store alongside the event (e.g the id of the object the event relates to)
     * @param int|null    $iCreatedBy The user who is creating the event, defaults to active user
     * @param string|null $sCreated   The date/time the event is recorded at, default so now.
     *
     * @return int
     * @throws AuthException
     * @throws ModelException
     */
    public function create(
        string $sType,
        $mData = null,
        int $iRef = null,
        int $iCreatedBy = null,
        string $sCreated = null
    ): int {

        /**
         * When logged in as an admin events should not be created. Hide admin activity on
         * production only, all other environments should generate events so they can be tested.
         */

        if (Environment::is(Environment::ENV_PROD) && wasAdmin()) {
            return true;
        } elseif (empty($sType)) {
            throw new AuthException('Event type not defined.');
        }

        // --------------------------------------------------------------------------

        $aData = [
            'type' => $sType,
            'url'  => uri_string(),
            'data' => $mData ? json_encode($mData) : null,
            'ref'  => (int) $iRef ?: null,
        ];

        if (!empty($iCreatedBy)) {
            $aData[$this->oModel->getColumnCreatedBy()]  = $iCreatedBy;
            $aData[$this->oModel->getColumnModifiedBy()] = $iCreatedBy;
        }

        if (!empty($sCreated)) {
            $aData[$this->oModel->getColumnCreated()]  = $sCreated;
            $aData[$this->oModel->getColumnModified()] = $sCreated;
        }

        // --------------------------------------------------------------------------

        $iId = $this->oModel->create($aData);

        if (!$iId) {
            throw new AuthException('Failed to create event. ' . $this->oModel->lastError());
        }

        return $iId;
    }
}
