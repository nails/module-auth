<?php

namespace Nails\Auth\Factory;

use Nails\Common\Service\Input;
use Nails\Factory;

abstract class AuthUrl
{
    protected string $path;

    // --------------------------------------------------------------------------

    /**
     * @param string|null $returnTo null = no return to is set; empty = current URL; not-empty = supplied
     * @param array       $query    Additional items to add to the query string
     *
     * @throws FactoryException
     */
    public function __construct(protected ?string $returnTo = '', protected array $query = [])
    {
        if ($returnTo !== null) {
            if (!empty($returnTo)) {
                $this->returnTo = siteUrl($returnTo);

            } else {
                /** @var Input $input */
                $input          = Factory::service('Input');
                $this->returnTo = sprintf(
                    '%s%s',
                    siteUrl(uri_string()),
                    $input::server('QUERY_STRING')
                        ? '?' . $input::server('QUERY_STRING')
                        : ''
                );

                //  If return to is homepage then remove, results in a tidier URL
                if ($this->returnTo === siteUrl()) {
                    $this->returnTo = '';
                }
            }
        }
    }

    // --------------------------------------------------------------------------

    public function getPath(): string
    {
        return $this->path;
    }

    // --------------------------------------------------------------------------

    public function setPath(string $path): self
    {
        $this->path = $path;
        return $this;
    }

    // --------------------------------------------------------------------------

    public function getReturnTo(): string
    {
        return $this->returnTo;
    }

    // --------------------------------------------------------------------------

    public function setReturnTo(string $returnTo): self
    {
        $this->returnTo = $returnTo;
        return $this;
    }

    // --------------------------------------------------------------------------

    public function getQuery(): string
    {
        return $this->query;
    }

    // --------------------------------------------------------------------------

    public function setQuery(array $query): self
    {
        $this->query = $query;
        return $this;
    }

    // --------------------------------------------------------------------------

    public function __toString(): string
    {
        $query = $this->getQuery();

        if ($this->getReturnTo()) {
            $query['return_to'] = $this->getReturnTo();
        }

        return siteUrl(sprintf(
            '%s%s',
            $this->getPath(),
            !empty($query)
                ? '?' . http_build_query($query)
                : ''
        ));
    }
}