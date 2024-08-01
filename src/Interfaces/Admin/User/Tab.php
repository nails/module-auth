<?php

namespace Nails\Auth\Interfaces\Admin\User;

use Nails\Auth\Resource\User;

/**
 * Interface Tab
 *
 * @package Nails\Auth\Interfaces\Admin\User
 */
interface Tab
{
    /**
     * Returns the tab's label
     *
     * @return string
     */
    public function getLabel(): string;

    // --------------------------------------------------------------------------

    /**
     * Returns whetehr the tab should be displayed or not
     *
     * @param User $user The user being edited
     */
    public static function isEnabled(User $user): bool;

    // --------------------------------------------------------------------------

    /**
     * Return the order in which the tabs should render
     *
     * @return float|null
     */
    public function getOrder(): ?float;

    // --------------------------------------------------------------------------

    /**
     * Returns the tab's body
     *
     * @param User $oUser The user being edited
     *
     * @return string
     */
    public function getBody(User $oUser): string;

    // --------------------------------------------------------------------------

    /**
     * Returns additional markup, outside of the main <form> element
     *
     * @param User $oUser The user being edited
     *
     * @return string
     */
    public function getAdditionalMarkup(User $oUser): string;

    // --------------------------------------------------------------------------

    /**
     * Returns an array of validation rules compatible with Validator objects
     *
     * @param User $oUser The user being edited
     *
     * @return array
     */
    public function getValidationRules(User $oUser): array;

    // --------------------------------------------------------------------------

    /**
     * Returns a key/value array of columns and the data to populate
     *
     * @param User  $oUser The user being edited
     * @param array $aPost The POST array
     *
     * @return array
     */
    public function getPostData(User $oUser, array $aPost): array;
}
