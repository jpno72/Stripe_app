<?php
declare(strict_types=1);
final class Passwords
{
    public static function validate(#[SensitiveParameter] string $password): void
    {
        // Avoid bcrypt's silent 72-byte truncation on PHP builds using PASSWORD_DEFAULT=bcrypt.
        if(strlen($password)<12||strlen($password)>72||str_contains($password,"\0"))throw new InvalidArgumentException('Use a password of 12–72 bytes without NUL characters.');
    }
    public static function hash(#[SensitiveParameter] string $password): string {self::validate($password);return password_hash($password,PASSWORD_DEFAULT);}
}
