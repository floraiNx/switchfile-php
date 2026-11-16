<?php

declare(strict_types=1);

namespace Switchfile\Exceptions;

/** The API answered with an error (4xx) or was unreachable. Name as in the CloudConvert SDK. */
class HttpClientException extends SwitchfileException
{
}
