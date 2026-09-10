<?php

/**
 * Passkey registration and authentication endpoints
 *
 * @package     Nails
 * @subpackage  module-auth
 * @category    Controller
 * @author      Nails Dev Team
 * @link
 */

namespace Nails\Auth\Api\Controller;

use Nails\Api;
use Nails\Api\Factory\ApiResponse;
use Nails\Auth\Constants;
use Nails\Auth\Exception\Login\InvalidCredentialsException;
use Nails\Auth\Exception\Login\IsLockedOutException;
use Nails\Auth\Exception\Login\IsSuspendedException;
use Nails\Auth\Exception\Login\NoUserException;
use Nails\Auth\Exception\Passkey\ChallengeException;
use Nails\Auth\Exception\Passkey\CredentialExistsException;
use Nails\Auth\Exception\Passkey\InvalidResponseException;
use Nails\Auth\Exception\Passkey\OriginNotAllowedException;
use Nails\Auth\Exception\Passkey\PasskeyException;
use Nails\Auth\Model\User\Passkey as PasskeyModel;
use Nails\Auth\Resource;
use Nails\Auth\Service\Authentication;
use Nails\Auth\Service\Passkey as PasskeyService;
use Nails\Common\Exception\FactoryException;
use Nails\Common\Exception\ValidationException;
use Nails\Common\Service\HttpCodes;
use Nails\Common\Service\Input;
use Nails\Common\Service\UserFeedback;
use Nails\Config;
use Nails\Factory;

/**
 * Class Passkey
 *
 * Method names are single lowercase words: the router builds the handler name as
 * {verb}{ucfirst(method)} and does no hyphen handling.
 *
 * @package Nails\Auth\Api\Controller
 */
class Passkey extends Api\Controller\Base
{
    /**
     * The endpoints which may be called by a logged out visitor
     *
     * @var string[]
     */
    const PUBLIC_METHODS = ['challenge', 'assert'];

    // --------------------------------------------------------------------------

    /**
     * Whether the caller may use the requested endpoint
     *
     * @param string $sHttpMethod The HTTP Method protocol being used
     * @param string $sMethod     The controller method being executed
     *
     * @return bool|array<string, mixed>
     */
    public static function isAuthenticated($sHttpMethod = '', $sMethod = '')
    {
        if (in_array(strtolower((string) $sMethod), static::PUBLIC_METHODS, true)) {
            return true;
        }

        return isLoggedIn();
    }

    // --------------------------------------------------------------------------

    /**
     * Lists the active user's passkeys
     *
     * @throws Api\Exception\ApiException
     * @throws FactoryException
     */
    public function getIndex(): ApiResponse
    {
        $this->assertEnabled();

        /** @var PasskeyModel $oModel */
        $oModel = Factory::model('UserPasskey', Constants::MODULE_SLUG);

        return $this
            ->response()
            ->setData(array_map(
                fn(Resource\User\Passkey $oPasskey): array => $this->formatPasskey($oPasskey),
                $oModel->getByUserId((int) activeUser('id'))
            ));
    }

    // --------------------------------------------------------------------------

    /**
     * Begins registration: returns creation options and remembers the challenge
     *
     * @throws Api\Exception\ApiException
     * @throws FactoryException
     */
    public function postRegister(): ApiResponse
    {
        $this->assertEnabled();
        $this->assertJsonRequest();
        $this->assertSameOriginRequest();

        return $this->guard(function (): ApiResponse {

            $oOptions = $this
                ->passkeyService()
                ->createRegistrationOptions($this->activeUserResource());

            return $this
                ->response()
                ->setData(['options' => $oOptions->options]);
        });
    }

    // --------------------------------------------------------------------------

    /**
     * Completes registration and stores the passkey
     *
     * @throws Api\Exception\ApiException
     * @throws FactoryException
     */
    public function postAttest(): ApiResponse
    {
        $this->assertEnabled();
        $this->assertJsonRequest();
        $this->assertSameOriginRequest();

        return $this->guard(function (): ApiResponse {

            $aData    = $this->getRequestData();
            $oService = $this->passkeyService();
            $oUser    = $this->activeUserResource();

            $sChallenge = $oService->consumeChallenge(
                PasskeyService::PURPOSE_REGISTRATION,
                (int) $oUser->id
            );

            $oPasskey = $oService->completeRegistration(
                $oUser,
                $this->requireCredential($aData),
                $sChallenge,
                is_string($aData['label'] ?? null) ? $aData['label'] : null
            );

            //  They have one now, so stop nudging them on this browser
            $oService->setNudgeDismissed();

            return $this
                ->response()
                ->setData(['passkey' => $this->formatPasskey($oPasskey)]);
        });
    }

    // --------------------------------------------------------------------------

    /**
     * Mints a discoverable authentication challenge for a logged out visitor
     *
     * @throws Api\Exception\ApiException
     * @throws FactoryException
     */
    public function postChallenge(): ApiResponse
    {
        $this->assertEnabled();
        $this->assertJsonRequest();
        $this->assertSameOriginRequest();

        return $this->guard(function (): ApiResponse {

            $oOptions = $this
                ->passkeyService()
                ->createAuthenticationOptions(null, PasskeyService::UV_REQUIRED);

            return $this
                ->response()
                ->setData(['options' => $oOptions->options]);
        });
    }

    // --------------------------------------------------------------------------

    /**
     * Verifies an assertion and logs the user in
     *
     * @throws Api\Exception\ApiException
     * @throws FactoryException
     */
    public function postAssert(): ApiResponse
    {
        $this->assertEnabled();
        $this->assertJsonRequest();
        $this->assertSameOriginRequest();

        return $this->guard(function (): ApiResponse {

            $aData    = $this->getRequestData();
            $oService = $this->passkeyService();
            /** @var Authentication $oAuthService */
            $oAuthService = Factory::service('Authentication', Constants::MODULE_SLUG);

            $sChallenge = $oService->consumeChallenge(PasskeyService::PURPOSE_AUTHENTICATION, null);

            $oUser = $oAuthService->loginWithPasskey(
                $this->requireCredential($aData),
                $sChallenge,
                (bool) ($aData['remember'] ?? false)
            );

            $this->welcome($oUser);

            createUserEvent('did_log_in', ['provider' => 'passkey']);

            return $this
                ->response()
                ->setData([
                    'redirect' => $this->sanitiseReturnTo(
                        is_string($aData['return_to'] ?? null) ? $aData['return_to'] : null,
                        $oUser
                    ),
                ]);
        });
    }

    // --------------------------------------------------------------------------

    /**
     * Renames one of the active user's passkeys
     *
     * @throws Api\Exception\ApiException
     * @throws FactoryException
     */
    public function postRename(): ApiResponse
    {
        $this->assertEnabled();
        $this->assertJsonRequest();
        $this->assertSameOriginRequest();

        return $this->guard(function (): ApiResponse {

            $aData    = $this->getRequestData();
            $oPasskey = $this->requireOwnedPasskey($aData);
            $sLabel   = trim((string) ($aData['label'] ?? ''));

            if ($sLabel === '') {
                throw new ValidationException('A label is required.');
            }

            $this->passkeyService()->rename($oPasskey, $sLabel);

            /** @var PasskeyModel $oModel */
            $oModel = Factory::model('UserPasskey', Constants::MODULE_SLUG);
            /** @var Resource\User\Passkey|null $oUpdated */
            $oUpdated = $oModel->getById((int) $oPasskey->id);

            return $this
                ->response()
                ->setData(['passkey' => $this->formatPasskey($oUpdated ?? $oPasskey)]);
        });
    }

    // --------------------------------------------------------------------------

    /**
     * Removes one of the active user's passkeys
     *
     * @throws Api\Exception\ApiException
     * @throws FactoryException
     */
    public function postRevoke(): ApiResponse
    {
        $this->assertEnabled();
        $this->assertJsonRequest();
        $this->assertSameOriginRequest();

        return $this->guard(function (): ApiResponse {

            $this->passkeyService()->revoke(
                $this->requireOwnedPasskey($this->getRequestData())
            );

            return $this->response();
        });
    }

    // --------------------------------------------------------------------------
    //  Internals
    // --------------------------------------------------------------------------

    /**
     * Runs an endpoint, translating passkey failures into API responses
     *
     * @param callable(): ApiResponse $cCallback
     *
     * @throws Api\Exception\ApiException
     * @throws FactoryException
     * @throws ValidationException
     */
    protected function guard(callable $cCallback): ApiResponse
    {
        /** @var HttpCodes $oHttpCodes */
        $oHttpCodes = Factory::service('HttpCodes');

        try {

            return $cCallback();

        } catch (InvalidResponseException|ChallengeException|CredentialExistsException|OriginNotAllowedException $e) {

            throw new Api\Exception\ApiException(
                $e->getMessage(),
                $oHttpCodes::STATUS_BAD_REQUEST
            );

        } catch (IsLockedOutException|IsSuspendedException $e) {

            throw new Api\Exception\ApiException(
                $e->getMessage(),
                $oHttpCodes::STATUS_FORBIDDEN
            );

        } catch (NoUserException|InvalidCredentialsException $e) {

            //  Deliberately generic: the caller must not learn which credential exists
            throw new Api\Exception\ApiException(
                $e->getMessage() ?: lang('auth_login_fail_general'),
                $oHttpCodes::STATUS_UNAUTHORIZED
            );

        } catch (PasskeyException $e) {

            throw new Api\Exception\ApiException(
                lang('auth_login_fail_general'),
                $oHttpCodes::STATUS_UNAUTHORIZED
            );
        }
    }

    // --------------------------------------------------------------------------

    /**
     * 404s when passkeys are switched off, so the endpoints simply do not exist
     *
     * @throws Api\Exception\ApiException
     * @throws FactoryException
     */
    protected function assertEnabled(): void
    {
        if (!$this->passkeyService()->isEnabled()) {

            /** @var HttpCodes $oHttpCodes */
            $oHttpCodes = Factory::service('HttpCodes');

            throw new Api\Exception\ApiException(
                'Passkeys are not enabled.',
                $oHttpCodes::STATUS_NOT_FOUND
            );
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Requires a JSON body
     *
     * A form post cannot set this content type cross-origin without a preflight, so
     * requiring it keeps these endpoints out of reach of a simple cross-site form.
     *
     * @throws Api\Exception\ApiException
     * @throws FactoryException
     */
    protected function assertJsonRequest(): void
    {
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var HttpCodes $oHttpCodes */
        $oHttpCodes = Factory::service('HttpCodes');

        $sContentType = strtolower(trim(explode(';', (string) $oInput::header('Content-Type'))[0]));

        if ($sContentType !== 'application/json' || !empty($oInput->post())) {
            throw new Api\Exception\ApiException(
                'This endpoint requires a JSON request body.',
                $oHttpCodes::STATUS_BAD_REQUEST
            );
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Requires the request to have come from this site
     *
     * There is no CSRF token on API routes, so this leans on the browser's own
     * fetch metadata, falling back to Origin/Referer where it is not sent.
     *
     * @throws Api\Exception\ApiException
     * @throws FactoryException
     */
    protected function assertSameOriginRequest(): void
    {
        /** @var Input $oInput */
        $oInput = Factory::service('Input');
        /** @var HttpCodes $oHttpCodes */
        $oHttpCodes = Factory::service('HttpCodes');

        $sFetchSite = strtolower((string) $oInput::header('Sec-Fetch-Site'));

        if ($sFetchSite !== '') {
            if (in_array($sFetchSite, ['same-origin', 'none'], true)) {
                return;
            }

            throw new Api\Exception\ApiException(
                'Cross-site requests are not permitted.',
                $oHttpCodes::STATUS_BAD_REQUEST
            );
        }

        $sHost   = (string) parse_url((string) Config::get('BASE_URL'), PHP_URL_HOST);
        $sSource = (string) ($oInput::header('Origin') ?: $oInput::header('Referer'));

        if ($sSource === '' || strtolower((string) parse_url($sSource, PHP_URL_HOST)) !== strtolower($sHost)) {
            throw new Api\Exception\ApiException(
                'Cross-site requests are not permitted.',
                $oHttpCodes::STATUS_BAD_REQUEST
            );
        }
    }

    // --------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $aData
     *
     * @return array<string, mixed>
     * @throws InvalidResponseException
     */
    protected function requireCredential(array $aData): array
    {
        $mCredential = $aData['credential'] ?? null;

        if (is_object($mCredential)) {
            $mCredential = (array) $mCredential;
        }

        if (!is_array($mCredential)) {
            throw new InvalidResponseException('No credential was supplied.');
        }

        return $mCredential;
    }

    // --------------------------------------------------------------------------

    /**
     * Resolves a passkey ID from the request, refusing anybody else's
     *
     * @param array<string, mixed> $aData
     *
     * @throws FactoryException
     * @throws ValidationException
     */
    protected function requireOwnedPasskey(array $aData): Resource\User\Passkey
    {
        /** @var PasskeyModel $oModel */
        $oModel = Factory::model('UserPasskey', Constants::MODULE_SLUG);

        /** @var Resource\User\Passkey|null $oPasskey */
        $oPasskey = $oModel->getById((int) ($aData['id'] ?? 0));

        if (empty($oPasskey) || (int) $oPasskey->user_id !== (int) activeUser('id')) {
            throw new ValidationException('Unrecognised passkey.');
        }

        return $oPasskey;
    }

    // --------------------------------------------------------------------------

    /**
     * Restricts a return URL to this site, falling back to the group homepage
     *
     * @throws FactoryException
     */
    protected function sanitiseReturnTo(?string $sReturnTo, Resource\User $oUser): string
    {
        $sFallback = (string) ($oUser->group_homepage ?: siteUrl());
        $sReturnTo = trim((string) $sReturnTo);

        if ($sReturnTo === '') {
            return $sFallback;
        }

        //  A protocol-relative URL would leave the site while looking relative
        if (str_starts_with($sReturnTo, '//')) {
            return $sFallback;
        }

        $sHost = parse_url($sReturnTo, PHP_URL_HOST);

        if (empty($sHost)) {
            return siteUrl(ltrim($sReturnTo, '/'));
        }

        $sBaseHost = (string) parse_url((string) Config::get('BASE_URL'), PHP_URL_HOST);

        return strtolower((string) $sHost) === strtolower($sBaseHost)
            ? $sReturnTo
            : $sFallback;
    }

    // --------------------------------------------------------------------------

    /**
     * Adds the same welcome message a password login would have shown
     *
     * @throws FactoryException
     */
    protected function welcome(Resource\User $oUser): void
    {
        /** @var UserFeedback $oUserFeedback */
        $oUserFeedback = Factory::service('UserFeedback');

        if ($oUser->last_login) {
            $oUserFeedback->success(lang(
                'auth_login_ok_welcome',
                [$oUser->first_name, toUserDatetime($oUser->last_login)]
            ));
        } else {
            $oUserFeedback->success(lang('auth_login_ok_welcome_notime', [$oUser->first_name]));
        }
    }

    // --------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    protected function formatPasskey(Resource\User\Passkey $oPasskey): array
    {
        return [
            'id'           => (int) $oPasskey->id,
            'label'        => $oPasskey->label,
            'created'      => (string) $oPasskey->created,
            'last_used'    => $oPasskey->last_used ? (string) $oPasskey->last_used : null,
            'is_backed_up' => $oPasskey->is_backed_up,
            'transports'   => $oPasskey->getTransports(),
            'aaguid'       => $oPasskey->aaguid,
        ];
    }

    // --------------------------------------------------------------------------

    /**
     * @throws FactoryException
     */
    protected function passkeyService(): PasskeyService
    {
        /** @var PasskeyService $oService */
        $oService = Factory::service('Passkey', Constants::MODULE_SLUG);

        return $oService;
    }

    // --------------------------------------------------------------------------

    /**
     * @throws FactoryException
     */
    protected function activeUserResource(): Resource\User
    {
        /** @var Resource\User $oUser */
        $oUser = activeUser();

        return $oUser;
    }

    // --------------------------------------------------------------------------

    /**
     * @throws FactoryException
     */
    protected function response(): ApiResponse
    {
        /** @var ApiResponse $oResponse */
        $oResponse = Factory::factory('ApiResponse', Api\Constants::MODULE_SLUG);

        return $oResponse;
    }
}
