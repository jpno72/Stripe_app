<?php
declare(strict_types=1);

final class Migrations
{
    /** Repository SQL uses whole-line DELIMITER directives and end-of-line terminators. */
    public static function statements(string $sql): array
    {
        $delimiter = ';'; $buffer = ''; $out = [];
        foreach (preg_split('/\R/', $sql) as $line) {
            if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $m)) {
                if (trim($buffer) !== '') { throw new RuntimeException('Invalid migration delimiter boundary.'); }
                $delimiter = $m[1]; continue;
            }
            if (preg_match('/^\s*--/', $line) || trim($line) === '') { continue; }
            $buffer .= $line . "\n";
            if (str_ends_with(rtrim($line), $delimiter)) {
                $out[] = substr(rtrim($buffer), 0, -strlen($delimiter)); $buffer = '';
            }
        }
        if (trim($buffer) !== '') { throw new RuntimeException('Unterminated migration statement.'); }
        return $out;
    }

    public static function run(PDO $db, string $directory, bool $apply): array
    {
        $version = (string) $db->query('SELECT VERSION()')->fetchColumn();
        if (!preg_match('/(\d+\.\d+\.\d+)-MariaDB/i', $version, $m) || version_compare($m[1], '10.4.0', '<')) {
            throw new RuntimeException('This migration set requires MariaDB 10.4+; MySQL has not been validated.');
        }
        $name = (string) $db->query('SELECT DATABASE()')->fetchColumn();
        $lockName = 'bma_migrate_' . substr(hash('sha256', $name), 0, 40);
        $q = $db->prepare('SELECT GET_LOCK(?, 0)'); $q->execute([$lockName]);
        if ((int) $q->fetchColumn() !== 1) { throw new RuntimeException('Another schema migration is running.'); }
        try {
            $files = glob($directory . '/*.sql'); sort($files);
            if ($files === []) { throw new RuntimeException('No migrations found.'); }
            [$tables,$views] = self::objects($db);
            $applied = [];
            if ($tables !== [] || $views !== []) {
                if (!in_array('schema_migrations', $tables, true)) { throw new RuntimeException('Refusing a nonempty database without migration history.'); }
                $applied = $db->query('SELECT version, checksum FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);
                if ($applied === []) { throw new RuntimeException('Untracked or interrupted schema. Manual review required.'); }
            }
            $known = [];
            foreach ($files as $file) { $known[pathinfo($file, PATHINFO_FILENAME)] = hash_file('sha256', $file); }
            $missingEarlier = false;
            foreach ($known as $migration => $hash) {
                if (!isset($applied[$migration])) { $missingEarlier = true; }
                elseif ($missingEarlier || !hash_equals($hash, $applied[$migration])) { throw new RuntimeException('Migration history order or checksum mismatch.'); }
            }
            foreach ($applied as $migration => $hash) {
                if (!isset($known[$migration])) { throw new RuntimeException('Unknown or interrupted migration. Manual review required.'); }
            }
            if ($applied !== []) {
                $expected = []; $expectedViews = [];
                foreach ($files as $file) {
                    if (isset($applied[pathinfo($file, PATHINFO_FILENAME)])) {
                        $sql = file_get_contents($file);
                        preg_match_all('/CREATE TABLE ([a-z_]+)/', $sql, $matches);
                        $expected = [...$expected, ...$matches[1]];
                        preg_match_all('/CREATE(?: OR REPLACE)?(?: SQL SECURITY INVOKER)? VIEW ([a-z_]+)/i', $sql, $viewMatches);
                        $expectedViews = [...$expectedViews, ...$viewMatches[1]];
                    }
                }
                if (array_diff($tables,$expected) !== [] || array_diff($expected,$tables) !== []
                    || array_diff($views,$expectedViews) !== [] || array_diff($expectedViews,$views) !== []) {
                    throw new RuntimeException('Table or view inventory differs from migration history. Manual review required.');
                }
            }
            $pending = array_diff_key($known, $applied);
            if (!$apply) { return array_keys($pending); }
            foreach ($files as $file) {
                $migration = pathinfo($file, PATHINFO_FILENAME);
                if (!isset($pending[$migration])) { continue; }
                if ($migration === '002_step1_contract') {
                    foreach (['raw_stripe_customers','raw_stripe_subscriptions','raw_stripe_transactions','raw_cognito_waivers','import_errors','cognito_waivers'] as $table) {
                        if ((int) $db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() > 0) {
                            throw new RuntimeException('Legacy source/waiver/error records need an explicit retention migration before 002. No automatic deletion.');
                        }
                    }
                    if ((int)$db->query("SELECT COUNT(*) FROM stripe_customers c WHERE c.person_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM identity_links l WHERE l.source_system = 'stripe' AND l.source_type = 'customer' AND l.external_id = c.stripe_customer_id AND l.source_role = 'customer' AND l.person_id = c.person_id AND l.match_status IN ('automatic','confirmed'))")->fetchColumn() > 0) {
                        throw new RuntimeException('Legacy customer associations need authoritative identity links before 002.');
                    }
                }
                if ($migration === '003_minimize_stripe_fields') {
                    // Never silently drop already-retained values from an existing installation.
                    foreach (['raw_stripe_customers','raw_stripe_subscriptions','raw_stripe_transactions'] as $table) {
                        if ((int)$db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() > 0) {
                            throw new RuntimeException('Historical Stripe staging rows require reviewed removal of newly ignored fields before 003.');
                        }
                    }
                    foreach ([
                        'stripe_customers' => 'description IS NOT NULL OR card_or_payment_method_id IS NOT NULL',
                        'stripe_subscriptions' => 'customer_description_snapshot IS NOT NULL',
                        'stripe_transactions' => 'customer_description_snapshot IS NOT NULL OR card_or_payment_method_id IS NOT NULL',
                    ] as $table => $condition) {
                        if ((int)$db->query('SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $condition)->fetchColumn() > 0) {
                            throw new RuntimeException('Historical Stripe values require reviewed removal before 003.');
                        }
                    }
                }
                $statements = self::statements(file_get_contents($file)); $marked = false;
                if (in_array('schema_migrations', $tables, true)) {
                    self::mark($db, 'in_progress:' . $migration, $known[$migration]); $marked = true;
                }
                foreach ($statements as $statement) {
                    $db->exec($statement);
                    if (!$marked && preg_match('/^CREATE TABLE schema_migrations\b/', $statement)) {
                        self::mark($db, 'in_progress:' . $migration, $known[$migration]); $marked = true;
                    }
                }
                $db->beginTransaction();
                self::mark($db, $migration, $known[$migration]);
                $q = $db->prepare('DELETE FROM schema_migrations WHERE version = ?'); $q->execute(['in_progress:' . $migration]);
                $db->commit();
                [$tables,$views] = self::objects($db);
            }
            return array_keys($pending);
        } finally {
            if ($db->inTransaction()) { $db->rollBack(); }
            $q = $db->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$lockName]);
        }
    }

    private static function mark(PDO $db, string $version, string $hash): void
    {
        $q = $db->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)'); $q->execute([$version, $hash]);
    }

    /** SHOW TABLES includes views; migration inventory tracks both kinds separately. */
    private static function objects(PDO $db): array
    {
        $tables = []; $views = [];
        foreach ($db->query('SHOW FULL TABLES')->fetchAll(PDO::FETCH_NUM) as $row) {
            if ($row[1] === 'VIEW') { $views[] = $row[0]; }
            else { $tables[] = $row[0]; }
        }
        return [$tables,$views];
    }
}
