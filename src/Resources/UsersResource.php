<?php

declare(strict_types=1);

namespace Switchfile\Resources;

use Switchfile\Models\User;
use Switchfile\Transport\HttpTransport;

class UsersResource
{
    public function __construct(private readonly HttpTransport $http)
    {
    }

    public function me(): User
    {
        return new User($this->http->request('GET', '/v2/users/me')['data']);
    }
}
