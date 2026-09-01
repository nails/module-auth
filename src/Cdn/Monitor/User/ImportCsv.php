<?php

namespace Nails\Auth\Cdn\Monitor\User;

use Nails\Auth\Cdn\MetaData\SystemKey;
use Nails\Cdn\Cdn\Monitor\ObjectHasMetaDataKey;

class ImportCsv extends ObjectHasMetaDataKey
{
    public function getLabel(): string
    {
        return 'User Import CSV';
    }

    protected function getKey(): string
    {
        return (new SystemKey\UserImport)->get();
    }
}
