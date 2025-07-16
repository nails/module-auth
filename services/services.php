<?php

use Nails\Auth\Factory;
use Nails\Auth\Model;
use Nails\Auth\Resource;
use Nails\Auth\Service;

return [
    'services'  => [
        'Authentication' => function (): Service\Authentication {
            if (class_exists('\App\Auth\Service\Authentication')) {
                return new \App\Auth\Service\Authentication();
            } else {
                return new Service\Authentication();
            }
        },
        'Session'        => function (): Service\Session {
            if (class_exists('\App\Auth\Service\Session')) {
                return new \App\Auth\Service\Session();
            } else {
                return new Service\Session();
            }
        },
        'SocialSignOn'   => function (): Service\SocialSignOn {
            if (class_exists('\App\Auth\Service\SocialSignOn')) {
                return new \App\Auth\Service\SocialSignOn();
            } else {
                return new Service\SocialSignOn();
            }
        },
        'UserEvent'      => function (): Service\User\Event {
            if (class_exists('\App\Auth\Service\User\Event')) {
                return new \App\Auth\Service\User\Event();
            } else {
                return new Service\User\Event();
            }
        },
        'UserMeta'       => function (): Service\User\Meta {
            if (class_exists('\App\Auth\Service\User\Meta')) {
                return new \App\Auth\Service\User\Meta();
            } else {
                return new Service\User\Meta();
            }
        },
    ],
    'models'    => [
        'User'                => function (): Model\User {
            if (class_exists('\App\Auth\Model\User')) {
                return new \App\Auth\Model\User();
            } else {
                return new Model\User();
            }
        },
        'UserAccessToken'     => function (): Model\User\AccessToken {
            if (class_exists('\App\Auth\Model\User\AccessToken')) {
                return new \App\Auth\Model\User\AccessToken();
            } else {
                return new Model\User\AccessToken();
            }
        },
        'UserEmail'           => function (): Model\User\Email {
            if (class_exists('\App\Auth\Model\User\Email')) {
                return new \App\Auth\Model\User\Email();
            } else {
                return new Model\User\Email();
            }
        },
        'UserEmailBlocker'    => function (): Model\User\Email\Blocker {
            if (class_exists('\App\Auth\Model\User\Email\Blocker')) {
                return new \App\Auth\Model\User\Email\Blocker();
            } else {
                return new Model\User\Email\Blocker();
            }
        },
        'UserEvent'           => function (): Model\User\Event {
            if (class_exists('\App\Auth\Model\User\Event')) {
                return new \App\Auth\Model\User\Event();
            } else {
                return new Model\User\Event();
            }
        },
        'UserGroup'           => function (): Model\User\Group {
            if (class_exists('\App\Auth\Model\User\Group')) {
                return new \App\Auth\Model\User\Group();
            } else {
                return new Model\User\Group();
            }
        },
        'UserPassword'        => function (): Model\User\Password {
            //  @todo (Pablo 2025-07-15) - this should be a service
            if (class_exists('\App\Auth\Model\User\Password')) {
                return new \App\Auth\Model\User\Password();
            } else {
                return new Model\User\Password();
            }
        },
        'UserPasswordHistory' => function (): Model\User\Password\History {
            if (class_exists('\App\Auth\Model\User\Password\History')) {
                return new \App\Auth\Model\User\Password\History();
            } else {
                return new Model\User\Password\History();
            }
        },
    ],
    'factories' => [
        'AuthUrlLogin'           => function (?string $returnTo = '', array $query = []): Factory\AuthUrl\Login {
            if (class_exists('\App\Auth\Factory\AuthUrl\Login')) {
                return new \App\Auth\Factory\AuthUrl\Login($returnTo, $query);
            } else {
                return new Factory\AuthUrl\Login($returnTo, $query);
            }
        },
        'AuthUrlRegister'        => function (?string $returnTo = '', array $query = []): Factory\AuthUrl\Register {
            if (class_exists('\App\Auth\Factory\AuthUrl\Register')) {
                return new \App\Auth\Factory\AuthUrl\Register($returnTo, $query);
            } else {
                return new Factory\AuthUrl\Register($returnTo, $query);
            }
        },
        'EmailForgottenPassword' => function (): Factory\Email\ForgottenPassword {
            if (class_exists('\App\Auth\Factory\Email\ForgottenPassword')) {
                return new \App\Auth\Factory\Email\ForgottenPassword();
            } else {
                return new Factory\Email\ForgottenPassword();
            }
        },
        'EmailNewUser'           => function (): Factory\Email\NewUser {
            if (class_exists('\App\Auth\Factory\Email\NewUser')) {
                return new \App\Auth\Factory\Email\NewUser();
            } else {
                return new Factory\Email\NewUser();
            }
        },
        'EmailPasswordUpdated'   => function (): Factory\Email\PasswordUpdated {
            if (class_exists('\App\Auth\Factory\Email\PasswordUpdated')) {
                return new \App\Auth\Factory\Email\PasswordUpdated();
            } else {
                return new Factory\Email\PasswordUpdated();
            }
        },
        'EmailVerifyEmail'       => function (): Factory\Email\VerifyEmail {
            if (class_exists('\App\Auth\Factory\Email\VerifyEmail')) {
                return new \App\Auth\Factory\Email\VerifyEmail();
            } else {
                return new Factory\Email\VerifyEmail();
            }
        },
    ],
    'resources' => [
        'User'                => function ($resource, $model): Resource\User {
            if (class_exists('\App\Auth\Resource\User')) {
                return new \App\Auth\Resource\User($resource, $model);
            } else {
                return new Resource\User($resource, $model);
            }
        },
        'UserAccessToken'     => function ($resource, $model): Resource\User\AccessToken {
            if (class_exists('\App\Auth\Resource\User\AccessToken')) {
                return new \App\Auth\Resource\User\AccessToken($resource, $model);
            } else {
                return new Resource\User\AccessToken($resource, $model);
            }
        },
        'UserAdminRecovery'   => function ($resource, $model = null): Resource\User\AdminRecovery {
            //  @todo (Pablo 2025-07-15) - this should be a factory
            if (class_exists('\App\Auth\Resource\User\AdminRecovery')) {
                return new \App\Auth\Resource\User\AdminRecovery($resource);
            } else {
                return new Resource\User\AdminRecovery($resource);
            }
        },
        'UserEmail'           => function ($resource, $model): Resource\User\Email {
            if (class_exists('\App\Auth\Resource\User\Email')) {
                return new \App\Auth\Resource\User\Email($resource, $model);
            } else {
                return new Resource\User\Email($resource, $model);
            }
        },
        'UserEmailBlocker'    => function ($resource, $model): Resource\User\Email\Blocker {
            if (class_exists('\App\Auth\Resource\User\Email\Blocker')) {
                return new \App\Auth\Resource\User\Email\Blocker($resource, $model);
            } else {
                return new Resource\User\Email\Blocker($resource, $model);
            }
        },
        'UserEvent'           => function ($resource, $model): Resource\User\Event {
            if (class_exists('\App\Auth\Resource\User\Event')) {
                return new \App\Auth\Resource\User\Event($resource, $model);
            } else {
                return new Resource\User\Event($resource, $model);
            }
        },
        'UserGroup'           => function ($resource, $model): Resource\User\Group {
            if (class_exists('\App\Auth\Resource\User\Group')) {
                return new \App\Auth\Resource\User\Group($resource, $model);
            } else {
                return new Resource\User\Group($resource, $model);
            }
        },
        'UserPasswordHistory' => function ($resource, $model): Resource\User\Password\History {
            if (class_exists('\App\Auth\Resource\User\Password\History')) {
                return new \App\Auth\Resource\User\Password\History($resource, $model);
            } else {
                return new Resource\User\Password\History($resource, $model);
            }
        },
    ],
];
