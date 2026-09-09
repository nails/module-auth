<?php

namespace Nails\Auth\Interfaces\Admin\User\Group;

use Nails\Auth\Resource\User\Group;

interface Tab
{
    public function getLabel(): string;

    // --------------------------------------------------------------------------

    public static function isEnabled(?Group $oGroup): bool;

    // --------------------------------------------------------------------------

    public function getOrder(): ?float;

    // --------------------------------------------------------------------------

    public function getBody(?Group $oGroup): string;

    // --------------------------------------------------------------------------

    public function getAdditionalMarkup(?Group $oGroup): string;

    // --------------------------------------------------------------------------

    public function getValidationRules(?Group $oGroup): array;

    // --------------------------------------------------------------------------

    public function getPostData(?Group $oGroup, array $aPost): array;

    // --------------------------------------------------------------------------

    public function afterSave(Group $oGroup, array $aPost): void;
}
