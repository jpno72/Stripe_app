<?php
declare(strict_types=1);
ini_set('zend.exception_ignore_args', '1');

require_once dirname(__DIR__, 2) . '/src/database.php';
require_once dirname(__DIR__, 2) . '/src/IdentityAssociations.php';
require_once __DIR__ . '/MatchScorer.php';
require_once __DIR__ . '/PersonResolver.php';
require_once __DIR__ . '/IdentityMatcher.php';
