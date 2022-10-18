<?php

use Nails\Factory;

/** @var \Nails\Common\Service\View $oView */
$oView = Factory::service('View');

echo form_open();
echo \Nails\Admin\Helper::tabs([
    [
        'label'   => 'Basic Details',
        'content' => function () use ($oView) {
            return \Nails\Admin\Helper::loadInlineView('edit/basic', [], true);
        },
    ],
    [
        'label'   => 'Password',
        'content' => function () use ($oView) {
            return \Nails\Admin\Helper::loadInlineView('edit/password', [], true);
        },
    ],
    [
        'label'   => '2FA',
        'content' => function () use ($oView) {
            return \Nails\Admin\Helper::loadInlineView('edit/2fa', [], true);
        },
    ],
    [
        'label'   => 'Permissions',
        'content' => function () use ($oView) {
            return \Nails\Admin\Helper::loadInlineView('edit/permissions', [], true);
        },
    ],
]);
echo \Nails\Admin\Helper::floatingControls();
echo form_close();
