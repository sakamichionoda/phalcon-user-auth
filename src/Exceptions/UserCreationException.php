<?php

namespace UserAuth\Exceptions;

use \Phalcon\Exception;
use UserAuth\Libraries\Utils;

/**
 * Class UserCreationException
 * @package UserAuth\Exceptions
 * @author Tega Oghenekohwo <tega@cottacush.com>
 */
class UserCreationException extends Exception
{
    public function __construct($message, $code = 0, Exception $previous = null)
    {
        $messages = Utils::getMessagesFromStringOrArray($message);

        // make sure everything is assigned properly
        parent::__construct($messages, $code, $previous);
    }
}