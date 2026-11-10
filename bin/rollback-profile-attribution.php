<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/src/database.php';
if(($argv[1]??'')!=='--apply'){fwrite(STDERR,"Requires explicit --apply and an empty attribution table. Back up first.\n");exit(1);}
try{
    $db=bmaDatabase(bmaConfig());$version='012_profile_transaction_attribution';
    $lock='bma_stripe_review_'.substr(hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn()),0,32);$q=$db->prepare('SELECT GET_LOCK(?,0)');$q->execute([$lock]);if((int)$q->fetchColumn()!==1)throw new RuntimeException();
    $q=$db->prepare('SELECT checksum FROM schema_migrations WHERE version=?');$q->execute([$version]);$checksum=$q->fetchColumn();
    if($checksum!==hash_file('sha256',dirname(__DIR__).'/sql/migrations/'.$version.'.sql')||$db->query('SELECT MAX(version) FROM schema_migrations')->fetchColumn()!==$version)throw new RuntimeException();
    if((int)$db->query('SELECT COUNT(*) FROM stripe_transaction_attributions')->fetchColumn()!==0)throw new RuntimeException();
    // No financial/source table, identity association or audit record is removed.
    $db->exec('DROP TABLE stripe_transaction_attributions');$db->prepare('DELETE FROM schema_migrations WHERE version=?')->execute([$version]);echo "Attribution migration rolled back; source data and audit history preserved.\n";
}catch(Throwable){fwrite(STDERR,"Rollback refused or incomplete. Check migration state privately; never delete nonempty allocations automatically.\n");exit(1);}
finally{if(isset($db,$lock)){$q=$db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$lock]);}}
