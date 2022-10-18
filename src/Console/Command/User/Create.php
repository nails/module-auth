<?php

namespace Nails\Auth\Console\Command\User;

use Exception;
use Nails\Admin\Admin\Permission\SuperUser;
use Nails\Auth\Constants;
use Nails\Common\Exception\NailsException;
use Nails\Config;
use Nails\Console\Command\Base;
use Nails\Environment;
use Nails\Factory;
use stdClass;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class Create
 *
 * @package Nails\Auth\Console\Command\User
 */
class Create extends Base
{
    /**
     * Configures the command
     */
    protected function configure()
    {
        $this->setName('make:user')
            ->setDescription('Creates a new super user')
            ->addOption(
                'defaults',
                'd',
                InputOption::VALUE_NONE,
                'Use Default Values'
            );

        //  Allow user to pass in specific fields; these will override any values picked up using --default
        foreach (['first_name', 'last_name', 'username', 'email', 'password'] as $sField) {
            $this->addOption(
                $sField,
                substr($sField, 0, 1),
                InputOption::VALUE_OPTIONAL,
                'The user\'s ' . str_replace('_', ' ', $sField)
            );
        }

        //  Allow the user to specify database details
        foreach (['host', 'username', 'password', 'name'] as $sField) {
            $this->addOption(
                'db-' . $sField,
                null,
                InputOption::VALUE_OPTIONAL,
                'The database ' . $sField
            );
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Executes the app
     *
     * @param InputInterface  $oInput  The Input Interface provided by Symfony
     * @param OutputInterface $oOutput The Output Interface provided by Symfony
     *
     * @return int
     * @throws Exception
     */
    protected function execute(InputInterface $oInput, OutputInterface $oOutput)
    {
        parent::execute($oInput, $oOutput);

        $this->banner('Create a new super user');

        // --------------------------------------------------------------------------

        //  Check environment
        if (Environment::is(Environment::ENV_PROD)) {

            $oOutput->writeln('');
            $oOutput->writeln('--------------------------------------');
            $oOutput->writeln('| <info>WARNING: The app is in PRODUCTION.</info> |');
            $oOutput->writeln('--------------------------------------');
            $oOutput->writeln('');

            if (!$this->confirm('Continue with user generation?', true)) {
                return $this->abort(static::EXIT_CODE_SUCCESS);
            }
        }

        // --------------------------------------------------------------------------

        //  Detect super group ID
        $oDb = Factory::service('PDODatabase');

        //  If any DB credentials have been passed then connect using those
        $sDbHost = $oInput->getOption('db-host');
        $sDbUser = $oInput->getOption('db-username');
        $sDbPass = $oInput->getOption('db-password');
        $sDbName = $oInput->getOption('db-name');

        if (!empty($sDbHost) || !empty($sDbUser) || !empty($sDbPass) || !empty($sDbName)) {
            $oDb->connect($sDbHost, $sDbUser, $sDbPass, $sDbName);
        }

        $oResult = $oDb->query(
            'SELECT id, label FROM `' . Config::get('NAILS_DB_PREFIX') . 'user_group` WHERE `acl` LIKE \'%"' . SuperUser::class . '"%\' LIMIT 1'
        );
        if (!$oResult->rowCount()) {
            throw new NailsException('Could not find a group with superuser permissions.');
        }
        $oGroup = $oResult->fetchObject();

        // --------------------------------------------------------------------------

        //  Are we using defaults?
        $bDefaults = $oInput->getOption('defaults');
        if ($bDefaults) {
            $aUser = $this->getDefaultUser();
        } else {
            $oOutput->writeln('');
            $aUser = [
                'first_name' => '',
                'last_name'  => '',
                'username'   => '',
                'email'      => '',
                'password'   => '',
            ];
        }

        //  Ask for any fields which are empty
        foreach ($aUser as $sField => &$sValue) {

            //  Check if an argument has been passed, overwrite if so
            $sArgument = $oInput->getOption($sField);
            if (!empty($sArgument)) {
                $sValue = $sArgument;
            } elseif (empty($sValue)) {
                $sField = ucwords(strtolower(str_replace('_', ' ', $sField)));
                $sError = '';
                do {
                    $sValue = $this->ask($sError . $sField . ':', '');
                    $sError = '<error>Please specify</error> ';
                } while (empty($sValue));
            }
        }
        unset($sValue);

        //  Confirm
        $oOutput->writeln('');
        $oOutput->writeln('OK, here\'s what\'s going to happen:');
        $oOutput->writeln('');
        $oOutput->writeln('Create a user with the following details:');
        $oOutput->writeln('');
        $oOutput->writeln(' - ' . str_pad('Group ID:', 11) . ' <comment>' . $oGroup->id . ' (' . $oGroup->label . ')</comment>');
        foreach ($aUser as $sField => $sValue) {
            $sField = ucwords(strtolower(str_replace('_', ' ', $sField)));
            $oOutput->writeln(' - ' . str_pad($sField . ':', 11) . ' <comment>' . $sValue . '</comment>');
        }
        $oOutput->writeln('');

        //  Execute
        if (!$this->confirm('Continue?', true)) {
            return $this->abort();
        }

        // --------------------------------------------------------------------------

        $oOutput->writeln('');
        $oOutput->write('Creating User... ');
        $this->createUser($aUser, $oGroup->id);
        $oOutput->writeln('<comment>done!</comment>');

        // --------------------------------------------------------------------------

        //  Cleaning up
        $oOutput->writeln('');
        $oOutput->writeln('<comment>Cleaning up</comment>...');

        // --------------------------------------------------------------------------

        //  And we're done
        $oOutput->writeln('');
        $oOutput->writeln('Complete!');

        return self::EXIT_CODE_SUCCESS;
    }

    // --------------------------------------------------------------------------

    /*
     * Get the default user as defined in the tool's config file (at ~/.nails)
     */
    private function getDefaultUser()
    {
        $oDefault    = new stdClass();
        $sConfigFile = $_SERVER['HOME'] . '/.nails';
        if (file_exists($sConfigFile)) {
            $sJson = file_get_contents($sConfigFile);
            $oJson = json_decode($sJson);
            if (!empty($oJson->{Constants::MODULE_SLUG}->default_user)) {
                $oDefault = $oJson->{Constants::MODULE_SLUG}->default_user;
            }
        }

        return [
            'first_name' => !empty($oDefault->first_name) ? $oDefault->first_name : '',
            'last_name'  => !empty($oDefault->last_name) ? $oDefault->last_name : '',
            'username'   => !empty($oDefault->username) ? $oDefault->username : '',
            'email'      => !empty($oDefault->email) ? $oDefault->email : '',
            'password'   => !empty($oDefault->password) ? $oDefault->password : '',
        ];
    }

    // --------------------------------------------------------------------------

    /**
     * Create the user
     *
     * @param array   $aUser    The details to create the user with
     * @param integer $iGroupId The user's Group Id
     *
     * @throws Exception
     */
    private function createUser($aUser, $iGroupId)
    {
        $oUserModel        = Factory::model('User', Constants::MODULE_SLUG);
        $aUser['group_id'] = $iGroupId;
        try {
            $oUser = $oUserModel->create($aUser, false);
            if (empty($oUser)) {
                throw new NailsException($oUserModel->lastError());
            }
        } catch (Exception $e) {
            if (!empty($oUser)) {
                $oUserModel->delete($oUser->id);
            }
            throw $e;
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Performs the abort functionality and returns the exit code
     *
     * @param array   $aMessages The error message
     * @param integer $iExitCode The exit code
     *
     * @return int
     */
    protected function abort($iExitCode = self::EXIT_CODE_FAILURE, array $aMessages = [])
    {
        $aMessages[] = 'Aborting user creation';
        return parent::abort($iExitCode, $aMessages);
    }
}
