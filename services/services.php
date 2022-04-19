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
        'User'             => function (): Model\User {
            if (class_exists('\App\Auth\Model\User')) {
                return new \App\Auth\Model\User();
            } else {
                return new Model\User();
            }
        },
        'UserAccessToken'  => function (): Model\User\AccessToken {
            if (class_exists('\App\Auth\Model\User\AccessToken')) {
                return new \App\Auth\Model\User\AccessToken();
            } else {
                return new Model\User\AccessToken();
            }
        },
        'UserEmail'        => function (): Model\User\Email {
            if (class_exists('\App\Auth\Model\User\Email')) {
                return new \App\Auth\Model\User\Email();
            } else {
                return new Model\User\Email();
            }
        },
        'UserEmailBlocker' => function (): Model\User\Email\Blocker {
            if (class_exists('\App\Auth\Model\User\Email\Blocker')) {
                return new \App\Auth\Model\User\Email\Blocker();
            } else {
                return new Model\User\Email\Blocker();
            }
        },
        'UserEvent'        => function (): Model\User\Event {
            if (class_exists('\App\Auth\Model\User\Event')) {
                return new \App\Auth\Model\User\Event();
            } else {
                return new Model\User\Event();
            }
        },
        'UserGroup'        => function (): Model\User\Group {
            if (class_exists('\App\Auth\Model\User\Group')) {
                return new \App\Auth\Model\User\Group();
            } else {
                return new Model\User\Group();
            }
        },
        'UserPassword'     => function (): Model\User\Password {
            if (class_exists('\App\Auth\Model\User\Password')) {
                return new \App\Auth\Model\User\Password();
            } else {
                return new Model\User\Password();
            }
        },
    ],
    'factories' => [
        'EmailForgottenPassword' => function (): Factory\Email\ForgottenPassword {
            return new Factory\Email\ForgottenPassword();
        },
        'EmailNewUser'           => function (): Factory\Email\NewUser {
            return new Factory\Email\NewUser();
        },
        'EmailPasswordUpdated'   => function (): Factory\Email\PasswordUpdated {
            return new Factory\Email\PasswordUpdated();
        },
        'EmailVerifyEmail'       => function (): Factory\Email\VerifyEmail {
            return new Factory\Email\VerifyEmail();
        },
    ],
    'resources' => [
        'User'              => function ($mObj): Resource\User {
            if (class_exists('\App\Auth\Resource\User')) {
                return new \App\Auth\Resource\User($mObj);
            } else {
                return new Resource\User($mObj);
            }
        },
        'UserAccessToken'   => function ($mObj): Resource\User\AccessToken {
            if (class_exists('\App\Auth\Resource\User\AccessToken')) {
                return new \App\Auth\Resource\User\AccessToken($mObj);
            } else {
                return new Resource\User\AccessToken($mObj);
            }
        },
        'UserAdminRecovery' => function ($mObj): Resource\User\AdminRecovery {
            if (class_exists('\App\Auth\Resource\User\AdminRecovery')) {
                return new \App\Auth\Resource\User\AdminRecovery($mObj);
            } else {
                return new Resource\User\AdminRecovery($mObj);
            }
        },
        'UserEmail'         => function ($mObj): Resource\User\Email {
            if (class_exists('\App\Auth\Resource\User\Email')) {
                return new \App\Auth\Resource\User\Email($mObj);
            } else {
                return new Resource\User\Email($mObj);
            }
        },
        'UserEmailBlocker'  => function ($mObj): Resource\User\Email\Blocker {
            if (class_exists('\App\Auth\Resource\User\Email\Blocker')) {
                return new \App\Auth\Resource\User\Email\Blocker($mObj);
            } else {
                return new Resource\User\Email\Blocker($mObj);
            }
        },
        'UserEvent'         => function ($mObj): Resource\User\Event {
            if (class_exists('\App\Auth\Resource\User\Event')) {
                return new \App\Auth\Resource\User\Event($mObj);
            } else {
                return new Resource\User\Event($mObj);
            }
        },
        'UserGroup'         => function ($mObj): Resource\User\Group {
            if (class_exists('\App\Auth\Resource\User\Group')) {
                return new \App\Auth\Resource\User\Group($mObj);
            } else {
                return new Resource\User\Group($mObj);
            }
        },
    ],
];
