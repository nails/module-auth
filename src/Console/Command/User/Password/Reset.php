<?php

namespace Nails\Auth\Console\Command\User\Password;

use Nails\Auth\Constants;
use Nails\Auth\Exception\Console\PasswordNotAcceptableException;
use Nails\Auth\Exception\Console\UserNotFoundException;
use Nails\Auth\Model\User;
use Nails\Console\Command\Base;
use Nails\Email\Service\Emailer;
use Nails\Factory;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class Reset
 *
 * @package Nails\Auth\Console\Command\User\Password
 */
class Reset extends Base
{
    /**
     * Configures the command
     */
    protected function configure()
    {
        $this
            ->setName('user:password:reset')
            ->setDescription('Resets a user\'s password')
            ->addOption(
                'user',
                'u',
                InputOption::VALUE_REQUIRED,
                'The users ID, email, or username'
            )
            ->addOption(
                'password',
                'p',
                InputOption::VALUE_OPTIONAL,
                'The password to set'
            )
            ->addOption(
                'temp',
                't',
                InputOption::VALUE_NONE,
                'Whether the user will be asked to change their password on log in'
            );
    }

    // --------------------------------------------------------------------------

    /**
     * Executes the app
     *
     * @param InputInterface  $oInput  The Input Interface provided by Symfony
     * @param OutputInterface $oOutput The Output Interface provided by Symfony
     *
     * @return int
     * @throws \Exception
     */
    protected function execute(InputInterface $oInput, OutputInterface $oOutput)
    {
        parent::execute($oInput, $oOutput);

        $this->banner('Reset a user\'s password');

        // --------------------------------------------------------------------------

        $sUser     = (string) $this->oInput->getOption('user');
        $sPassword = $this->oInput->getOption('password');
        $bIsTemp   = $this->oInput->getOption('temp');

        // --------------------------------------------------------------------------

        if (empty($sUser)) {
            throw new UserNotFoundException('A user ID, email, or username is required; use option --user');
        }

        // --------------------------------------------------------------------------

        /** @var User $oUserModel */
        $oUserModel = Factory::model('User', Constants::MODULE_SLUG);
        /** @var User\Password $oUserPasswordModel */
        $oUserPasswordModel = Factory::model('UserPassword', Constants::MODULE_SLUG);

        // --------------------------------------------------------------------------

        if (is_numeric($sUser)) {
            $oUser = $oUserModel->getById($sUser);
        } else {
            $oUser = $oUserModel->getByEmail($sUser);
            if (empty($oUser)) {
                $oUser = $oUserModel->getByUsername($sUser);
            }
        }

        if (empty($oUser)) {
            throw new UserNotFoundException('Could not find a user by ID, email, or username "' . $sUser . '"');
        }

        if (!empty($sPassword)) {
            if (!$oUserPasswordModel->isAcceptable($oUser, $sPassword)) {
                throw new PasswordNotAcceptableException(
                    '"' . $sPassword . '" is not an acceptable password. ' .
                    $oUserPasswordModel->getRulesAsString($oUser->group_id)
                );
            }
            $bGenerated = false;
        } else {
            $sPassword  = $oUserPasswordModel->generate($oUser->group_id);
            $bGenerated = true;
        }

        // --------------------------------------------------------------------------

        //  Confirm
        $oOutput->writeln('');
        $oOutput->writeln('OK, here\'s what\'s going to happen:');
        $oOutput->writeln('');
        $oOutput->writeln(sprintf(
            '- The password for user <info>#%s %s (%s)</info> will be changed.',
            $oUser->id,
            $oUser->name,
            $oUser->email
        ));
        $oOutput->writeln('- The user will be informed their password has changed, but not what it is.');
        if ($bIsTemp) {
            $oOutput->writeln('- The password is temporary and the user will be asked to change this on next log in.');
        }
        $oOutput->writeln('');
        if (!$this->confirm('Continue?', true)) {
            return self::EXIT_CODE_FAILURE;
        }

        // --------------------------------------------------------------------------

        if (!$oUserPasswordModel->change($oUser->id, $sPassword, $bIsTemp)) {
            $this->error(array_filter([
                'Failed to change password',
                $oUserPasswordModel->lastError(),
            ]));
            return self::EXIT_CODE_FAILURE;
        }

        // --------------------------------------------------------------------------

        //  Fetch the reset email
        /** @var Emailer $oEmailer */
        $oEmailer   = Factory::service('Emailer', \Nails\Email\Constants::MODULE_SLUG);
        $oLastEmail = $oEmailer->getLastEmail();

        if (!empty($oLastEmail)) {
            $oOutput->writeln('Password reset confirmation email which was sent to user:');
            $oOutput->writeln(sprintf(
                '<comment>%s</comment>',
                $oLastEmail->data->url->viewOnline
            ));
        }

        // --------------------------------------------------------------------------

        //  And we're done
        $oOutput->writeln('');
        $oOutput->writeln('Complete!');
        $oOutput->writeln('');

        if ($bGenerated) {
            $this->warning([
                'The generated password is: ' . $sPassword . '',
                'It is up to you to tell the user their new password.',
            ]);
        }

        return self::EXIT_CODE_SUCCESS;
    }
}
