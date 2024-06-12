<?php

namespace Nails\Auth\Factory;

use Nails\Common\Service\Input;
use Nails\Factory;

class RegisterUrl
{
    protected string $path     = 'auth/register';
    protected string $returnTo = '';

    public function __construct(bool $autoReturnTo = true)
    {
        if ($autoReturnTo) {
            /** @var Input $input */
            $input          = Factory::service('Input');
            $this->returnTo = $input::server('REQUEST_URI');
        }
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function setPath(string $path): self
    {
        $this->path = $path;
        return $this;
    }

    public function getReturnTo(): string
    {
        return $this->returnTo;
    }

    public function setReturnTo(string $returnTo): self
    {
        $this->returnTo = $returnTo;
        return $this;
    }

    public function __toString(): string
    {
        $query = [];

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
