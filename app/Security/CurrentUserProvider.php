<?php
declare(strict_types=1);
require_once __DIR__.'/Authenticator.php';
interface CurrentUserProvider { public function current(): ?array; }
final class SessionCurrentUserProvider implements CurrentUserProvider
{
    public function __construct(private PDO $db,private array $config) {}
    public function current(): ?array { return (new Authenticator($this->db,$this->config))->current(); }
}
