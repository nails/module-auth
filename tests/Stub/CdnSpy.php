<?php

namespace Tests\Auth\Stub;

use Nails\Cdn\Resource;
use Nails\Cdn\Service\Cdn;

/**
 * A stand-in for the CDN service which records the objects it was asked to
 * destroy, and can be told to fail.
 *
 * The real service reaches for the FileCache service and the config in its
 * constructor, so the constructor is replaced outright rather than mocked.
 */
class CdnSpy extends Cdn
{
    /**
     * Every object ID passed to objectDestroy(), in order
     *
     * @var array<int, int|string|Resource\CdnObject|null>
     */
    public array $aDestroyed = [];

    /**
     * Object IDs which objectDestroy() should report as failed by returning
     * false, mapped onto the error it should then report
     *
     * @var array<int, string>
     */
    public array $aFailures = [];

    /**
     * Object IDs which objectDestroy() should throw for, mapped onto the
     * exception message
     *
     * @var array<int, string>
     */
    public array $aThrows = [];

    // --------------------------------------------------------------------------

    /**
     * Every set of arguments passed to objectCreate(), in order
     *
     * @var array<int, array{path: mixed, bucket: mixed, options: array}>
     */
    public array $aCreated = [];

    /**
     * What objectCreate() should hand back
     *
     * Defaults to a plain stdClass carrying an ID, because that is what the real
     * service returns - objectCreate() ends at createObject(), which returns
     * Cdn::getObject(), declared `bool|stdClass`. Set to false to model a
     * failure, in which case $sObjectCreateError is reported alongside it.
     */
    public mixed $mObjectCreateReturn = null;

    /**
     * The error objectCreate() should report when it returns false
     */
    public ?string $sObjectCreateError = null;

    /**
     * The message objectCreate() should throw, if it should throw at all
     */
    public ?string $sObjectCreateThrow = null;

    // --------------------------------------------------------------------------

    public function __construct()
    {
        //  Deliberately does not stand up a driver
    }

    // --------------------------------------------------------------------------

    public function objectCreate($object, $mBucket, $aOptions = [], $bIsStream = false)
    {
        $this->aCreated[] = [
            'path'    => $object,
            'bucket'  => $mBucket,
            'options' => $aOptions,
        ];

        if ($this->sObjectCreateThrow !== null) {
            throw new \RuntimeException($this->sObjectCreateThrow);
        }

        if ($this->mObjectCreateReturn === false) {
            /**
             * The real service catches everything, sets an error and returns
             * false; it does not throw, which is what makes the reason so easy
             * to lose.
             */
            if ($this->sObjectCreateError !== null) {
                $this->setError($this->sObjectCreateError);
            }

            return false;
        }

        return $this->mObjectCreateReturn ?? (object) ['id' => 20103];
    }

    // --------------------------------------------------------------------------

    public function objectDestroy(int|string|Resource\CdnObject|null $object): bool
    {
        $this->aDestroyed[] = $object;

        if (array_key_exists((int) $object, $this->aThrows)) {
            throw new \RuntimeException($this->aThrows[(int) $object]);
        }

        if (array_key_exists((int) $object, $this->aFailures)) {
            /**
             * Mirrors the real service, which reports a missing object, a driver
             * failure, and a rolled back transaction by setting an error and
             * returning false rather than by throwing.
             */
            $this->setError($this->aFailures[(int) $object]);
            return false;
        }

        return true;
    }
}
