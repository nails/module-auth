<?php

namespace Nails\Auth\Housekeeping;

use Nails\Auth\Service\Authentication;
use Nails\Common\Service\Database;
use Nails\Factory;
use Nails\Housekeeping\Routine\Base;
use Nails\Housekeeping\Routine\Context;
use Nails\Housekeeping\Routine\Result;

class TwoFactorTokens extends Base
{
    const LABEL           = 'Legacy 2FA tokens';
    const DESCRIPTION     = 'Deletes expired legacy two-factor authentication tokens';
    const CRON_EXPRESSION = '*/15 * * * *';

    public function execute(Context $oContext): Result
    {
        /** @var Database $oDb */
        $oDb = Factory::service('Database');
        /** @var \DateTime $oNow */
        $oNow = Factory::factory('DateTime');

        $sTable     = Authentication::TABLE_TWO_FACTOR_TOKEN;
        $sCutOff    = $oNow->format('Y-m-d H:i:s');
        $iBatchSize = 200;
        $iProcessed = 0;
        $iLastId    = 0;

        $oContext
            ->writeln(sprintf('Deleting from <comment>%s</comment> in batches of %d', $sTable, $iBatchSize))
            ->log(sprintf(
                'TABLE %s batch_size=%d dry_run=%s',
                $sTable,
                $iBatchSize,
                $oContext->isDryRun() ? 'true' : 'false'
            ));

        while (true) {
            $aRows = $oDb
                ->select('id, user_id, expires')
                ->where('expires <', $sCutOff)
                ->where('id >', $iLastId)
                ->order_by('id', 'asc')
                ->limit($iBatchSize)
                ->get($sTable)
                ->result();

            if (empty($aRows)) {
                break;
            }

            $aIds = [];
            foreach ($aRows as $oRow) {
                $iId     = (int) $oRow->id;
                $iLastId = $iId;
                $aIds[]  = $iId;
                $sAudit  = sprintf(
                    'id=%d user_id=%s expires=%s',
                    $iId,
                    $oRow->user_id === null ? 'null' : (string) $oRow->user_id,
                    (string) $oRow->expires
                );
                $oContext
                    ->log('DELETE ' . $sAudit)
                    ->writeln(' ↳ ' . $sAudit);
            }

            if (!$oContext->isDryRun()) {
                $oDb
                    ->where_in('id', $aIds)
                    ->delete($sTable);
            }

            $iProcessed += count($aIds);
        }

        $oContext->writeln(sprintf(
            '<comment>%s</comment> %s',
            number_format($iProcessed),
            $oContext->isDryRun() ? 'would be deleted' : 'deleted'
        ));

        return Result::ok($iProcessed);
    }
}
