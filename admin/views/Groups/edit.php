<?php

/** @var \Nails\Common\Service\View $oView */
$oView = \Nails\Factory::service('View');

echo form_open();
$aTabs = [
    [
        'order'   => 0,
        'label'   => 'Basic Details',
        'content' => function () use ($oView) {
            return \Nails\Admin\Helper::loadInlineView('edit/basic', [], true);
        },
    ],
    [
        'order'   => 1,
        'label'   => 'Password',
        'content' => function () use ($oView) {
            return \Nails\Admin\Helper::loadInlineView('edit/password', [], true);
        },
    ],
    [
        'order'   => 2,
        'label'   => 'Permissions',
        'content' => function () use ($oView) {
            return \Nails\Admin\Helper::loadInlineView('edit/permissions', [], true);
        },
    ],
];

/** @var \Nails\Auth\Interfaces\Admin\User\Group\Tab $oTab */
foreach ($aGroupTabs ?? [] as $oTab) {
    $aTabs[] = [
        'order'   => $oTab->getOrder(),
        'label'   => $oTab->getLabel(),
        'content' => $oTab->getBody($item ?? null),
    ];
}

arraySortMulti($aTabs, 'order');
echo \Nails\Admin\Helper::tabs($aTabs);
echo \Nails\Admin\Helper::floatingControls();
echo form_close();

/** @var \Nails\Auth\Interfaces\Admin\User\Group\Tab $oTab */
foreach ($aGroupTabs ?? [] as $oTab) {
    echo $oTab->getAdditionalMarkup($item ?? null);
}
