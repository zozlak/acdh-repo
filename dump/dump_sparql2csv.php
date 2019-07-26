<?php
require_once 'vendor/autoload.php';
use acdhOeaw\fedora\Fedora;
use acdhOeaw\util\RepoConfig as RC;
RC::init(__DIR__ . '/config.ini');
$fedora = new Fedora();
$result = $fedora->runSparql('select * where {?a ?b ?c}');
$f = fopen('aaa.csv', 'w');
foreach ($result as $i) {
	$d = [(string) $i->a, (string) $i->b, (string) $i->c, '', ''];
	if (get_class($i->c) !== 'EasyRdf\Resource') {
		$d[3] = $i->c->getDatatypeUri();
		$d[4] = $i->c->getLang();
	} else {
		$d[3] = 'URI';
	}
	fputcsv($f, $d);
}
fclose($f);

