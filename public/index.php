<?php
declare(strict_types=1);
require "../vendor/autoload.php";
use Mlangeni\Machinjiri\Core\Machinjiri;
use Mlangeni\Machinjiri\Core\Database\QueryBuilder;
$machinjiri = new Machinjiri();
$machinjiri->init();

$query = new QueryBuilder("migrations");
$query->createTable("coding", [
  $query->id()->autoIncrement()->primaryKey(),
  $query->string("name")->notNull(),
  $query->date("created_at")
  ]);

print $query->compileCreateTable();

$query->execute();