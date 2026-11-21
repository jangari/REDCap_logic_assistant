<?php
namespace Intersect\LogicAssistant;

/**
 * An error whose message is written for the end user and is safe to show in the browser.
 * Any other exception is logged server-side and replaced with a generic message.
 */
class UserFacingException extends \Exception
{
}
