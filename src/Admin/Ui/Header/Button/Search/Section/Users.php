<?php

namespace Nails\Auth\Admin\Ui\Header\Button\Search\Section;

use Nails\Admin\Admin\Controller\Dashboard;
use Nails\Admin\Interfaces\Ui\Header\Button\Search\Section;
use Nails\Auth\Admin\Controller\Accounts;
use Nails\Auth\Admin\Permission\Users\LoginAs;
use Nails\Auth\Constants;
use Nails\Auth\Model\User;
use Nails\Common\Helper\Model\Like;
use Nails\Factory;

class Users implements Section
{
    protected User $oModel;

    // --------------------------------------------------------------------------

    public function __construct()
    {
        $this->oModel = Factory::model('User', Constants::MODULE_SLUG);
    }

    // --------------------------------------------------------------------------

    public function getLabel(): string
    {
        return 'Users';
    }

    // --------------------------------------------------------------------------

    public function getResults(string $sQuery): array
    {
        if (!$this->queryIsEmailOrMatchesName($sQuery)) {
            return [];
        }

        /** @var \Nails\Common\Service\Input $oInput */
        $oInput    = Factory::service('Input');
        $sReferrer = $oInput->server('HTTP_REFERER') ?: Dashboard::url();

        $aResults = [];
        $oResults = $this->oModel->search($sQuery, 1, 10);
        /** @var \Nails\Auth\Resource\User $oUser */
        foreach ($oResults->data as $oUser) {

            /** @var \Nails\Admin\Factory\Ui\Header\Button\Search\Result $oResult */
            $oResult = Factory::factory('UiHeaderButtonSearchResult', \Nails\Admin\Constants::MODULE_SLUG);
            /** @var \Nails\Admin\Factory\Ui\Header\Button\Search\Result\Action $oActionEdit */
            $oActionEdit = Factory::factory('UiHeaderButtonSearchResultAction', \Nails\Admin\Constants::MODULE_SLUG);

            if (userHasPermission(LoginAs::class) && activeUser('id') != $oUser->id) {
                /** @var \Nails\Admin\Factory\Ui\Header\Button\Search\Result\Action $oActionLoginAs */
                $oActionLoginAs = Factory::factory('UiHeaderButtonSearchResultAction', \Nails\Admin\Constants::MODULE_SLUG);
                $oActionLoginAs
                    ->setIconClass('fa-arrow-right')
                    ->setLabel('Log in as')
                    ->setUrl($oUser->getLoginUrl(null, $sReferrer));
            }

            $oActionEdit
                ->setIconClass('fa-edit')
                ->setLabel('Edit')
                ->setUrl(Accounts::url('edit/' . $oUser->id));

            $oResult
                ->setIconClass('fa-users')
                ->setLabel($oUser->name)
                ->setDescription($oUser->email)
                ->setActions(array_filter([
                    $oActionLoginAs ?? null,
                    $oActionEdit,
                ]));

            $aResults[] = $oResult;

        }

        return $aResults;
    }

    // --------------------------------------------------------------------------

    protected function queryIsEmailOrMatchesName(string $sQuery): bool
    {
        if (stripos($sQuery, '@') !== false) {
            return true;
        }

        /** @var \Nails\Common\Service\Database $oDb */
        $oDb = Factory::service('Database');
        return (bool) $oDb
            ->or_like('first_name', $sQuery, 'left')
            ->or_like('last_name', $sQuery, 'left')
            ->count_all_results($this->oModel->getTableName());
    }

    // --------------------------------------------------------------------------

    public static function getOrder(): int
    {
        return 0;
    }
}
